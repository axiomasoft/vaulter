import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick } from 'vue'
import { mount, flushPromises } from '@vue/test-utils'
import type { VaulterClient } from '../src/client'
import { provideVaulterClient } from '../src/plugin'
import { useSpreadsheet } from '../src/composables/useSpreadsheet'
import VaulterSpreadsheet from '../src/components/VaulterSpreadsheet.vue'
import type { SpreadsheetVersion, SpreadsheetWorkbook } from '../src/types'

function version(content: SpreadsheetWorkbook | null): SpreadsheetVersion {
  return {
    id: 2, node_id: 20, version_no: 1, format: 'univer', snapshot_label: null,
    is_autosave: true, keep_forever: false, status: 'committed', content_hash: null,
    yjs_state_size: null, created_by: null, created_at: null, content,
  }
}

/** Run a composable against a fake client and expose its return for the test to drive. */
function mountWith<R>(client: Partial<VaulterClient>, setup: () => R): { vm: R } {
  let captured!: R
  const Comp = defineComponent({
    setup() {
      captured = setup()
      return () => h('div')
    },
  })
  mount(Comp, {
    global: { plugins: [{ install(app) { provideVaulterClient(app, client as VaulterClient) } }] },
  })
  return { vm: captured }
}

function withClient(client: Partial<VaulterClient>) {
  return { global: { plugins: [{ install(app: import('vue').App) { provideVaulterClient(app, client as VaulterClient) } }] } }
}

describe('useSpreadsheet', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('loads the opaque workbook from the documentNodes.workbook seam', async () => {
    const workbook = vi.fn().mockResolvedValue(version({ id: 'wb1', sheets: {} }))
    const { vm } = mountWith({ documentNodes: { workbook } as never }, () => useSpreadsheet(20))

    await vm.load()

    expect(workbook).toHaveBeenCalledWith(20)
    expect(vm.workbook.value).toEqual({ id: 'wb1', sheets: {} })
    expect(vm.head.value?.node_id).toBe(20)
  })

  it('debounces autosave: rapid edits collapse to one updateWorkbook with the last value', async () => {
    const updateWorkbook = vi.fn().mockResolvedValue(version({ id: 'wb1' }))
    const { vm } = mountWith({ documentNodes: { updateWorkbook } as never }, () => useSpreadsheet(20, { debounceMs: 500 }))

    vm.setWorkbook({ id: 'wb1', rev: 1 })
    vm.setWorkbook({ id: 'wb1', rev: 2 })
    vm.setWorkbook({ id: 'wb1', rev: 3 })
    expect(updateWorkbook).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(500)
    await nextTick()

    expect(updateWorkbook).toHaveBeenCalledTimes(1)
    expect(updateWorkbook).toHaveBeenCalledWith(20, { id: 'wb1', rev: 3 }, undefined)
  })

  it('save() persists immediately; refuses when nothing is loaded/set', async () => {
    const updateWorkbook = vi.fn().mockResolvedValue(version({ id: 'wb1' }))
    const { vm } = mountWith({ documentNodes: { updateWorkbook } as never }, () => useSpreadsheet(20))

    await expect(vm.save()).rejects.toThrow('Nothing to save')

    vm.setWorkbook({ id: 'wb1' })
    // The debounce also schedules a save; flush the pending timer so it does not leak.
    await vm.save()
    await vi.runAllTimersAsync()

    expect(updateWorkbook).toHaveBeenCalledWith(20, { id: 'wb1' }, undefined)
  })
})

describe('VaulterSpreadsheet', () => {
  it('loads on mount and exposes the workbook + a save through the default slot', async () => {
    const workbook = vi.fn().mockResolvedValue(version({ id: 'wb1', name: 'Budget' }))
    const updateWorkbook = vi.fn().mockResolvedValue(version({ id: 'wb1', name: 'Budget' }))
    const handler = vi.fn().mockResolvedValue({ id: 'univer' })

    const wrapper = mount(VaulterSpreadsheet, {
      props: { nodeId: 20 },
      slots: {
        default: `<template #default="{ workbook }"><pre class="wb">{{ JSON.stringify(workbook) }}</pre></template>`,
      },
      ...withClient({ documentNodes: { workbook, updateWorkbook, handler } as never }),
    })

    await flushPromises()

    expect(workbook).toHaveBeenCalledWith(20)
    expect(wrapper.get('.wb').text()).toContain('Budget')
    expect(wrapper.classes()).toContain('vaulter-spreadsheet')
  })

  it('surfaces a load failure in the error slot', async () => {
    const workbook = vi.fn().mockRejectedValue(new Error('boom'))
    const handler = vi.fn().mockResolvedValue({ id: 'univer' })

    const wrapper = mount(VaulterSpreadsheet, {
      props: { nodeId: 20 },
      ...withClient({ documentNodes: { workbook, handler } as never }),
    })

    await flushPromises()

    expect(wrapper.find('.vaulter-spreadsheet__error').exists()).toBe(true)
  })
})

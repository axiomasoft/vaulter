import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import VaulterCommentsPanel from '../src/components/VaulterCommentsPanel.vue'
import { pageOf } from './support/nodes'
import { withClient } from './support/mount'

/** A client mock exposing just the comment surface the panel drives. */
function mockClient(overrides: Record<string, unknown> = {}) {
  return {
    comments: {
      list: vi.fn().mockResolvedValue(pageOf([])),
      add: vi.fn(),
      resolve: vi.fn(),
      remove: vi.fn(),
      ...overrides,
    },
  }
}

describe('VaulterCommentsPanel', () => {
  it('mounts and loads the node\'s comments on mount', async () => {
    const client = mockClient({
      list: vi.fn().mockResolvedValue(pageOf([
        { id: '1', node_id: '42', parent_id: null, author: { type: 'user', id: '1' }, body: 'Hi', anchor: null, resolved_at: null, resolved_by: null, created_at: '2026-01-01T00:00:00Z' },
      ])),
    })
    const wrapper = mount(VaulterCommentsPanel, { props: { nodeId: '42' }, ...withClient(client) })

    expect(wrapper.classes()).toContain('vaulter-comments-panel')
    await flushPromises()
    expect(client.comments.list).toHaveBeenCalledWith('42')
    expect(wrapper.text()).toContain('Hi')
  })

  it('does not auto-load when autoLoad is false', () => {
    const client = mockClient()
    mount(VaulterCommentsPanel, { props: { nodeId: '42', autoLoad: false }, ...withClient(client) })

    expect(client.comments.list).not.toHaveBeenCalled()
  })

  it('announces a failed load and offers the next page', async () => {
    const failing = mockClient({ list: vi.fn().mockRejectedValue(new Error('boom')) })
    const failed = mount(VaulterCommentsPanel, { props: { nodeId: '42' }, ...withClient(failing) })
    await flushPromises()
    expect(failed.find('[role="alert"]').text()).toBe('Could not load comments.')

    const paged = mockClient({ list: vi.fn().mockResolvedValue(pageOf([], 'next-cursor')) })
    const more = mount(VaulterCommentsPanel, { props: { nodeId: '42' }, ...withClient(paged) })
    await flushPromises()
    expect(more.find('.vaulter-comments-panel__more').exists()).toBe(true)
  })
})

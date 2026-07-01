import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { flushPromises } from '@vue/test-utils'
import VaulterCommentsPanel from '../src/components/VaulterCommentsPanel.vue'
import { docVaultClientKey } from '../src/composables/useVaulterClient'
import type { VaulterClient } from '../src/client'

/** A node-only client mock exposing just the comment surface the panel drives. */
function mockClient(overrides: Partial<VaulterClient['comments']> = {}): VaulterClient {
  return {
    comments: {
      list: vi.fn().mockResolvedValue([]),
      create: vi.fn(),
      resolve: vi.fn(),
      remove: vi.fn(),
      ...overrides,
    },
  } as unknown as VaulterClient
}

function withClient(client: VaulterClient = mockClient()) {
  return { global: { provide: { [docVaultClientKey as symbol]: client } } }
}

describe('VaulterCommentsPanel (node — ADR-0014)', () => {
  it('mounts and loads the node\'s comments on mount', async () => {
    const client = mockClient({
      list: vi.fn().mockResolvedValue([
        { id: 1, node_id: 42, parent_id: null, author: { type: 'user', id: 1 }, body: 'Hi', anchor: null, resolved_at: null, created_at: null },
      ]),
    })
    const wrapper = mount(VaulterCommentsPanel, {
      props: { nodeId: 42 },
      ...withClient(client),
    })

    expect(wrapper.classes()).toContain('vaulter-comments-panel')
    await flushPromises()
    expect(client.comments.list).toHaveBeenCalledWith(42)
  })

  it('does not auto-load when autoLoad is false', () => {
    const client = mockClient()
    mount(VaulterCommentsPanel, {
      props: { nodeId: 42, autoLoad: false },
      ...withClient(client),
    })

    expect(client.comments.list).not.toHaveBeenCalled()
  })
})

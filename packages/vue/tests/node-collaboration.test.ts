import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import { Doc as YDoc } from 'yjs'
import type { VaulterClient } from '../src/client'
import { provideVaulterClient } from '../src/plugin'
import { useNodeCollaboration } from '../src/composables/useNodeCollaboration'
import { createNodeCollaborationSession } from '../src/nodeCollaboration'
import type { NodeCollaborationSession } from '../src/types'

// Mount a component that runs `setup()` against a fake client and captures its result.
function mountWith<R>(client: Partial<VaulterClient>, setup: () => R): { vm: R } {
  let captured: R
  const Comp = defineComponent({
    setup() {
      captured = setup()
      return () => h('div')
    },
  })
  mount(Comp, {
    global: {
      plugins: [{ install: (app) => provideVaulterClient(app, client as VaulterClient) }],
    },
  })
  return { vm: captured! }
}

function hocuspocusSession(readOnly = false): NodeCollaborationSession {
  return {
    driver: 'hocuspocus', room: 'vaulter.documents.42', node_id: 42,
    read_only: readOnly, token: 'jwt-token', url: 'ws://hub:1234', connection: null,
  }
}

function reverbSession(): NodeCollaborationSession {
  return {
    driver: 'reverb', room: 'vaulter.documents.42', node_id: 42,
    read_only: false, token: 'vaulter.documents.42', url: null, connection: 'reverb',
  }
}

// ─── createNodeCollaborationSession (the transport factory) ───────────────────

describe('createNodeCollaborationSession', () => {
  it('builds a hocuspocus session with mapped params and lifts awareness', () => {
    const destroy = vi.fn()
    const states = new Map<number, Record<string, unknown>>([[1, { user: { id: 9, name: 'Ann' } }]])
    const awareness = { getStates: () => states, on: vi.fn(), off: vi.fn() }
    const factory = vi.fn().mockReturnValue({
      awareness,
      isConnected: () => true,
      onConnectionChange: (h: (c: boolean) => void) => { h(true); return () => {} },
      destroy,
    })
    const doc = new YDoc()

    const handle = createNodeCollaborationSession(hocuspocusSession(false), {
      doc, createHocuspocusProvider: factory,
    })

    expect(handle.transport).toBe('hocuspocus')
    expect(handle.readOnly).toBe(false)
    expect(factory).toHaveBeenCalledWith({
      url: 'ws://hub:1234', name: 'vaulter.documents.42', token: 'jwt-token', document: doc,
    })
    expect(handle.connected.value).toBe(true)
    expect(handle.collaborators.value).toEqual([{ clientId: 1, user: { id: 9, name: 'Ann' } }])

    handle.destroy()
    expect(destroy).toHaveBeenCalledOnce()
  })

  it('carries read-only straight from the backend session', () => {
    const factory = vi.fn().mockReturnValue({ awareness: null, destroy: vi.fn() })
    const handle = createNodeCollaborationSession(hocuspocusSession(true), {
      doc: new YDoc(), createHocuspocusProvider: factory,
    })
    expect(handle.readOnly).toBe(true)
  })

  it('bridges a reverb session through the injected provider (channel = token)', () => {
    const factory = vi.fn().mockReturnValue({ awareness: null, destroy: vi.fn() })
    const doc = new YDoc()

    const handle = createNodeCollaborationSession(reverbSession(), { doc, createReverbProvider: factory })

    expect(handle.transport).toBe('reverb')
    expect(factory).toHaveBeenCalledWith({
      channel: 'vaulter.documents.42', connection: 'reverb', document: doc,
    })
  })

  it('throws for the reverb driver without a bridge factory (never hard-requires reverb)', () => {
    expect(() => createNodeCollaborationSession(reverbSession(), { doc: new YDoc() }))
      .toThrow(/reverb/)
  })
})

// ─── useNodeCollaboration (the composable) ────────────────────────────────────

describe('useNodeCollaboration', () => {
  it('fetches the token and builds a session (mocked provider + client)', async () => {
    const token = vi.fn().mockResolvedValue(hocuspocusSession(false))
    const destroy = vi.fn()
    const provider = vi.fn().mockReturnValue({
      awareness: null, isConnected: () => true, onConnectionChange: () => () => {}, destroy,
    })

    const { vm } = mountWith(
      { nodeCollaboration: { token } } as never,
      () => useNodeCollaboration(42, { providers: { createHocuspocusProvider: provider } }),
    )

    const handle = await vm.connect()

    expect(token).toHaveBeenCalledWith(42)
    expect(provider).toHaveBeenCalledOnce()
    // The provider received the composable's shared doc.
    expect(provider.mock.calls[0][0].document).toBe(vm.doc)
    expect(vm.session.value).toMatchObject({ driver: 'hocuspocus', room: 'vaulter.documents.42' })
    expect(vm.readOnly.value).toBe(false)
    expect(vm.connected.value).toBe(true)
    expect(handle.transport).toBe('hocuspocus')

    vm.disconnect()
    expect(destroy).toHaveBeenCalledOnce()
    expect(vm.handle.value).toBeNull()
  })

  it('respects a read-only session from the backend', async () => {
    const token = vi.fn().mockResolvedValue(hocuspocusSession(true))
    const provider = vi.fn().mockReturnValue({ awareness: null, destroy: vi.fn() })

    const { vm } = mountWith(
      { nodeCollaboration: { token } } as never,
      () => useNodeCollaboration(42, { providers: { createHocuspocusProvider: provider } }),
    )

    await vm.connect()
    expect(vm.readOnly.value).toBe(true)
  })

  it('fetchSession resolves the descriptor without opening a provider', async () => {
    const token = vi.fn().mockResolvedValue(hocuspocusSession(false))
    const provider = vi.fn()

    const { vm } = mountWith(
      { nodeCollaboration: { token } } as never,
      () => useNodeCollaboration(7, { providers: { createHocuspocusProvider: provider } }),
    )

    const session = await vm.fetchSession()
    expect(session.node_id).toBe(42)
    expect(provider).not.toHaveBeenCalled()
    expect(vm.handle.value).toBeNull()
  })

  it('surfaces a token-fetch error', async () => {
    const token = vi.fn().mockRejectedValue(new Error('forbidden'))
    const { vm } = mountWith(
      { nodeCollaboration: { token } } as never,
      () => useNodeCollaboration(42),
    )

    await expect(vm.connect()).rejects.toThrow(/forbidden/)
    expect(vm.error.value).toBeInstanceOf(Error)
    expect(vm.handle.value).toBeNull()
  })
})

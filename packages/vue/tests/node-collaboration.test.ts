import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import { Doc as YDoc } from 'yjs'
import type { VaulterClient } from '../src/client'
import { provideVaulterClient } from '../src/plugin'
import { useNodeCollaboration } from '../src/composables/useNodeCollaboration'
import {
  createNodeCollaborationSession,
  sessionEnd,
  type NodeCollaborationEnd,
  type NodeCollaborationProviderHandle,
} from '../src/nodeCollaboration'
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

function hocuspocusSession(overrides: Partial<NodeCollaborationSession> = {}): NodeCollaborationSession {
  return {
    driver: 'hocuspocus', room: 'vaulter.documents.7.42.0', node_id: '42', drive_id: '7',
    epoch: 0, schema_version: 1, mode: 'rw', token: 'jwt-token', url: 'ws://hub:1234',
    ...overrides,
  }
}

/** A stub provider whose `onEnd` handlers the test can fire, as the hub's close would. */
function endableProvider() {
  const handlers: Array<(end: NodeCollaborationEnd) => void> = []
  const destroy = vi.fn()
  const handle: NodeCollaborationProviderHandle = {
    awareness: null,
    isConnected: () => true,
    onConnectionChange: () => () => {},
    onEnd: (handler) => {
      handlers.push(handler)
      return () => {}
    },
    destroy,
  }
  return { handle, destroy, end: (why: NodeCollaborationEnd) => handlers.forEach((handler) => handler(why)) }
}

async function flush(): Promise<void> {
  for (let i = 0; i < 5; i++) {
    await Promise.resolve()
    await nextTick()
  }
}

// ─── createNodeCollaborationSession ───────────────────────────────────────────

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

    const handle = createNodeCollaborationSession(hocuspocusSession(), { doc, createHocuspocusProvider: factory })

    expect(handle.readOnly).toBe(false)
    expect(factory).toHaveBeenCalledWith({
      url: 'ws://hub:1234', name: 'vaulter.documents.7.42.0', token: 'jwt-token', document: doc,
    })
    expect(handle.connected.value).toBe(true)
    expect(handle.collaborators.value).toEqual([{ clientId: 1, user: { id: 9, name: 'Ann' } }])

    handle.destroy()
    expect(destroy).toHaveBeenCalledOnce()
  })

  it('is read-only for a ro session', () => {
    const factory = vi.fn().mockReturnValue({ awareness: null, destroy: vi.fn() })
    const handle = createNodeCollaborationSession(hocuspocusSession({ mode: 'ro' }), {
      doc: new YDoc(), createHocuspocusProvider: factory,
    })
    expect(handle.readOnly).toBe(true)
  })

  it('reads the hub\'s terminal closes', () => {
    expect(sessionEnd(4409, 'epoch_changed')).toBe('epoch_changed')
    expect(sessionEnd(undefined, 'epoch_changed')).toBe('epoch_changed')
    expect(sessionEnd(4001, 'Collaboration token expired')).toBe('token_expired')
    expect(sessionEnd(1006, '')).toBeNull()
    expect(sessionEnd(undefined, 'permission-denied')).toBeNull()
  })
})

// ─── useNodeCollaboration ─────────────────────────────────────────────────────

describe('useNodeCollaboration', () => {
  it('fetches the session and opens the provider on its Y.Doc', async () => {
    const token = vi.fn().mockResolvedValue(hocuspocusSession())
    const destroy = vi.fn()
    const provider = vi.fn().mockReturnValue({
      awareness: null, isConnected: () => true, onConnectionChange: () => () => {}, destroy,
    })

    const { vm } = mountWith(
      { nodeCollaboration: { token } } as never,
      () => useNodeCollaboration(42, { providers: { createHocuspocusProvider: provider } }),
    )

    await vm.connect()

    expect(token).toHaveBeenCalledWith(42, undefined)
    expect(provider).toHaveBeenCalledOnce()
    expect(provider.mock.calls[0][0].document).toBe(vm.doc.value)
    expect(vm.session.value).toMatchObject({ room: 'vaulter.documents.7.42.0', epoch: 0 })
    expect(vm.readOnly.value).toBe(false)
    expect(vm.connected.value).toBe(true)

    vm.disconnect()
    expect(destroy).toHaveBeenCalledOnce()
    expect(vm.handle.value).toBeNull()
  })

  it('is read-only for a ro session', async () => {
    const token = vi.fn().mockResolvedValue(hocuspocusSession({ mode: 'ro' }))
    const provider = vi.fn().mockReturnValue({ awareness: null, destroy: vi.fn() })

    const { vm } = mountWith(
      { nodeCollaboration: { token } } as never,
      () => useNodeCollaboration(42, { providers: { createHocuspocusProvider: provider } }),
    )

    await vm.connect()
    expect(vm.readOnly.value).toBe(true)
  })

  it('on 4409 epoch_changed destroys the provider and Y.Doc and reopens on a NEW Y.Doc', async () => {
    const first = endableProvider()
    const second = endableProvider()
    const token = vi.fn()
      .mockResolvedValueOnce(hocuspocusSession())
      .mockResolvedValueOnce(hocuspocusSession({ epoch: 1, room: 'vaulter.documents.7.42.1', token: 'jwt-2' }))
    const provider = vi.fn().mockReturnValueOnce(first.handle).mockReturnValueOnce(second.handle)

    const { vm } = mountWith(
      { nodeCollaboration: { token } } as never,
      () => useNodeCollaboration(42, { providers: { createHocuspocusProvider: provider } }),
    )
    await vm.connect()
    const oldDoc = vm.doc.value
    oldDoc.getText('t').insert(0, 'edit made before the restore')

    first.end('epoch_changed')
    await flush()

    expect(first.destroy).toHaveBeenCalledOnce()
    expect(token).toHaveBeenCalledTimes(2)
    expect(vm.doc.value).not.toBe(oldDoc)
    // Nothing of the old generation is carried into the new document.
    expect(vm.doc.value.getText('t').toString()).toBe('')
    expect(provider.mock.calls[1][0]).toMatchObject({ name: 'vaulter.documents.7.42.1', token: 'jwt-2' })
    expect(provider.mock.calls[1][0].document).toBe(vm.doc.value)
    expect(vm.session.value?.epoch).toBe(1)
  })

  it('on token expiry renews the session and keeps the Y.Doc of the same room', async () => {
    const first = endableProvider()
    const second = endableProvider()
    const token = vi.fn()
      .mockResolvedValueOnce(hocuspocusSession())
      .mockResolvedValueOnce(hocuspocusSession({ token: 'jwt-renewed' }))
    const provider = vi.fn().mockReturnValueOnce(first.handle).mockReturnValueOnce(second.handle)

    const { vm } = mountWith(
      { nodeCollaboration: { token } } as never,
      () => useNodeCollaboration(42, { providers: { createHocuspocusProvider: provider } }),
    )
    await vm.connect()
    const doc = vm.doc.value

    first.end('token_expired')
    await flush()

    expect(vm.doc.value).toBe(doc)
    expect(provider.mock.calls[1][0]).toMatchObject({ token: 'jwt-renewed', document: doc })
  })

  it('sends the supported schema version and opens a newer schema read-only', async () => {
    const token = vi.fn().mockResolvedValue(hocuspocusSession({ schema_version: 3 }))
    const provider = vi.fn().mockReturnValue({ awareness: null, destroy: vi.fn() })

    const { vm } = mountWith(
      { nodeCollaboration: { token } } as never,
      () => useNodeCollaboration(42, { providers: { createHocuspocusProvider: provider }, supportedSchemaVersion: 2 }),
    )

    await vm.connect()

    expect(token).toHaveBeenCalledWith(42, { schemaVersion: 2 })
    expect(vm.schemaOutdated.value).toBe(true)
    expect(vm.readOnly.value).toBe(true)
  })

  it('fetchSession resolves the descriptor without opening a provider', async () => {
    const token = vi.fn().mockResolvedValue(hocuspocusSession())
    const provider = vi.fn()

    const { vm } = mountWith(
      { nodeCollaboration: { token } } as never,
      () => useNodeCollaboration(7, { providers: { createHocuspocusProvider: provider } }),
    )

    const session = await vm.fetchSession()
    expect(session.node_id).toBe('42')
    expect(provider).not.toHaveBeenCalled()
    expect(vm.handle.value).toBeNull()
  })

  it('surfaces a session-fetch error', async () => {
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

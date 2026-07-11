import { describe, expect, it } from 'vitest'
import { HocuspocusProvider } from '@hocuspocus/provider'
import { Doc as YDoc } from 'yjs'
import { createNodeCollaborationSession } from '../src/nodeCollaboration'
import type { NodeCollaborationSession } from '../src/types'

// F-P4.5-02 — the @hocuspocus/provider 2.x → 4.x bump. Every other collaboration
// test injects a mock `createHocuspocusProvider`, so none of them exercise the real
// v4 API that `defaultHocuspocusProvider` depends on. These tests pin that contract
// against the REAL package: the `isSynced` getter and the `synced`/`disconnect`
// event names. If a future v4/v5 bump renames either, these go red — instead of the
// collaboration UI silently sticking on perpetually-(dis)connected with green CI.

function hocuspocusSession(): NodeCollaborationSession {
  return {
    driver: 'hocuspocus',
    room: 'vaulter.documents.42',
    node_id: 42,
    read_only: false,
    token: 'jwt-token',
    // Unroutable on purpose: the provider never establishes a live socket in the
    // sandbox; we only assert the synchronous construction/getter/awareness contract.
    url: 'ws://127.0.0.1:1',
    connection: null,
  }
}

describe('defaultHocuspocusProvider against the real @hocuspocus/provider v4', () => {
  it('builds a live session through the default (non-mocked) path', () => {
    // No `createHocuspocusProvider` override → the production default factory runs
    // against the real HocuspocusProvider constructor.
    const handle = createNodeCollaborationSession(hocuspocusSession(), { doc: new YDoc() })

    // `isConnected()` reads the real `provider.isSynced` getter — a boolean, not
    // `undefined` (which is what a renamed/removed getter would yield).
    expect(typeof handle.provider.isConnected?.()).toBe('boolean')
    expect(handle.connected.value).toBe(false)

    // Real v4 exposes an awareness instance we lift collaborators from.
    expect(handle.provider.awareness).toBeTruthy()

    // Subscribing/tearing down against the real emitter must not throw.
    expect(() => handle.destroy()).not.toThrow()
  })
})

describe('@hocuspocus/provider v4 public contract used by defaultHocuspocusProvider', () => {
  it('exposes the `isSynced` getter and emits `synced`/`disconnect` by those names', () => {
    // Keep the test hermetic — `connect: false` opens no real socket. It is a
    // valid runtime option; bind via a variable so structural typing accepts it
    // even though v4's published config type omits it.
    const config = {
      url: 'ws://127.0.0.1:1',
      name: 'vaulter.documents.42',
      token: 'jwt-token',
      document: new YDoc(),
      connect: false,
    }
    const provider = new HocuspocusProvider(config)

    // Getter contract: `provider.isSynced` is a real boolean the production code reads.
    expect(typeof provider.isSynced).toBe('boolean')
    expect(provider.isSynced).toBe(false)
    expect(provider.awareness).toBeTruthy()

    // Event-name contract: register exactly as `defaultHocuspocusProvider` does…
    let connected: boolean | null = null
    const onSynced = (): void => { connected = true }
    const onDisconnect = (): void => { connected = false }
    provider.on('synced', onSynced)
    provider.on('disconnect', onDisconnect)

    // …and drive the real code paths that emit those events in v4.
    // `synced` setter emits `synced` (the "first handshake → connected" signal).
    provider.synced = true
    expect(connected).toBe(true)
    expect(provider.isSynced).toBe(true)

    // A socket close runs the websocket `onClose` handler, which the provider
    // forwards as its `disconnect` event — the exact name production subscribes to.
    const ws = (provider as unknown as {
      configuration: { websocketProvider: { onClose: (payload: { event: { code: number } }) => void } }
    }).configuration.websocketProvider
    ws.onClose({ event: { code: 1006 } })
    expect(connected).toBe(false)

    // `off` unsubscribes (used by the returned disposer) without throwing.
    expect(() => {
      provider.off('synced', onSynced)
      provider.off('disconnect', onDisconnect)
    }).not.toThrow()

    provider.destroy()
  })
})

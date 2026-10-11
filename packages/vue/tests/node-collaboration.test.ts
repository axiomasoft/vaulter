import { describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { Doc as YDoc } from 'yjs'
import { CollaborationCancelledError, useCollaboration } from '../src/composables/useCollaboration'
import { createCollaborationSession, fetchCollaborationSession } from '../src/collaboration/session'
import {
  openCollaborationTransport,
  sessionEnd,
  type CollaborationEnd,
  type CollaborationProviderHandle,
} from '../src/collaboration/transport'
import type { VaulterClient } from '../src/api/client'
import type { CollaborationSession } from '../src/api/types'
import { mountWith } from './support/mount'

function hocuspocusSession(overrides: Partial<CollaborationSession> = {}): CollaborationSession {
  return {
    driver: 'hocuspocus', room: 'vaulter.documents.7.42.0', node_id: '42', drive_id: '7',
    epoch: 0, schema_version: 1, mode: 'rw', token: 'jwt-token', url: 'ws://hub:1234',
    ...overrides,
  }
}

/** A stub provider whose `onEnd` handlers the test can fire, as the hub's close would. */
function endableProvider() {
  const handlers: Array<(end: CollaborationEnd) => void> = []
  const destroy = vi.fn()
  const handle: CollaborationProviderHandle = {
    awareness: null,
    isConnected: () => true,
    onConnectionChange: () => () => {},
    onEnd: (handler) => {
      handlers.push(handler)
      return () => {}
    },
    destroy,
  }
  return { handle, destroy, end: (why: CollaborationEnd) => handlers.forEach((handler) => handler(why)) }
}

async function flush(): Promise<void> {
  for (let i = 0; i < 5; i++) {
    await Promise.resolve()
    await nextTick()
  }
}

// ─── openCollaborationTransport ───────────────────────────────────────────

describe('openCollaborationTransport', () => {
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

    const handle = openCollaborationTransport(hocuspocusSession(), { doc, createHocuspocusProvider: factory })

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
    const handle = openCollaborationTransport(hocuspocusSession({ mode: 'ro' }), {
      doc: new YDoc(), createHocuspocusProvider: factory,
    })
    expect(handle.readOnly).toBe(true)
  })

  it('reads the hub\'s terminal closes', () => {
    expect(sessionEnd(4409, 'epoch_changed')).toBe('epoch_changed')
    expect(sessionEnd(undefined, 'epoch_changed')).toBe('epoch_changed')
    expect(sessionEnd(4001, 'Collaboration token expired')).toBe('token_expired')
    expect(sessionEnd(1000, 'Collaboration token expired')).toBe('token_expired')
    // An authentication refusal (reconnect with an expired token) carries only the reason.
    expect(sessionEnd(undefined, 'Collaboration token expired')).toBe('token_expired')
    expect(sessionEnd(4403, 'forbidden')).toBe('forbidden')
    expect(sessionEnd(undefined, 'forbidden')).toBe('forbidden')
    expect(sessionEnd(4422, 'store_refused')).toBe('store_refused')
    expect(sessionEnd(1006, '')).toBeNull()
    expect(sessionEnd(undefined, 'permission-denied')).toBeNull()
  })
})

// ─── useCollaboration ─────────────────────────────────────────────────────

describe('useCollaboration', () => {
  it('fetches the session and opens the provider on its Y.Doc', async () => {
    const token = vi.fn().mockResolvedValue(hocuspocusSession())
    const destroy = vi.fn()
    const provider = vi.fn().mockReturnValue({
      awareness: null, isConnected: () => true, onConnectionChange: () => () => {}, destroy,
    })

    const { vm } = mountWith(
      { documents: { collaborationSession: token } },
      () => useCollaboration('42', { providers: { createHocuspocusProvider: provider } }),
    )

    await vm.connect()

    expect(token).toHaveBeenCalledWith('42', undefined)
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
      { documents: { collaborationSession: token } },
      () => useCollaboration('42', { providers: { createHocuspocusProvider: provider } }),
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
      { documents: { collaborationSession: token } },
      () => useCollaboration('42', { providers: { createHocuspocusProvider: provider } }),
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
      { documents: { collaborationSession: token } },
      () => useCollaboration('42', { providers: { createHocuspocusProvider: provider } }),
    )
    await vm.connect()
    const doc = vm.doc.value

    first.end('token_expired')
    await flush()

    expect(vm.doc.value).toBe(doc)
    expect(provider.mock.calls[1][0]).toMatchObject({ token: 'jwt-renewed', document: doc })
  })

  it('on 4403 forbidden renews the session (read-only now) on the same Y.Doc', async () => {
    const first = endableProvider()
    const second = endableProvider()
    const token = vi.fn()
      .mockResolvedValueOnce(hocuspocusSession())
      .mockResolvedValueOnce(hocuspocusSession({ mode: 'ro', token: 'jwt-ro' }))
    const provider = vi.fn().mockReturnValueOnce(first.handle).mockReturnValueOnce(second.handle)

    const { vm } = mountWith(
      { documents: { collaborationSession: token } },
      () => useCollaboration('42', { providers: { createHocuspocusProvider: provider } }),
    )
    await vm.connect()
    const doc = vm.doc.value

    first.end('forbidden')
    await flush()

    expect(vm.doc.value).toBe(doc)
    expect(vm.readOnly.value).toBe(true)
    expect(provider.mock.calls[1][0]).toMatchObject({ token: 'jwt-ro', document: doc })
  })

  it('on 4422 store_refused does not reopen: it reports an error and stays disconnected', async () => {
    const first = endableProvider()
    const token = vi.fn().mockResolvedValue(hocuspocusSession())
    const provider = vi.fn().mockReturnValue(first.handle)

    const { vm } = mountWith(
      { documents: { collaborationSession: token } },
      () => useCollaboration('42', { providers: { createHocuspocusProvider: provider } }),
    )
    await vm.connect()

    first.end('store_refused')
    await flush()

    expect(token).toHaveBeenCalledOnce()
    expect(provider).toHaveBeenCalledOnce()
    expect(first.destroy).toHaveBeenCalledOnce()
    expect(vm.connected.value).toBe(false)
    expect(vm.error.value).toBeInstanceOf(Error)
  })

  it('sends the supported schema version and opens a newer schema read-only', async () => {
    const token = vi.fn().mockResolvedValue(hocuspocusSession({ schema_version: 3 }))
    const provider = vi.fn().mockReturnValue({ awareness: null, destroy: vi.fn() })

    const { vm } = mountWith(
      { documents: { collaborationSession: token } },
      () => useCollaboration('42', { providers: { createHocuspocusProvider: provider }, supportedSchemaVersion: 2 }),
    )

    await vm.connect()

    expect(token).toHaveBeenCalledWith('42', { schemaVersion: 2 })
    expect(vm.schemaOutdated.value).toBe(true)
    expect(vm.readOnly.value).toBe(true)
  })

  it('surfaces a session-fetch error', async () => {
    const token = vi.fn().mockRejectedValue(new Error('forbidden'))
    const { vm } = mountWith(
      { documents: { collaborationSession: token } },
      () => useCollaboration('42'),
    )

    await expect(vm.connect()).rejects.toThrow(/forbidden/)
    expect(vm.error.value).toBeInstanceOf(Error)
    expect(vm.handle.value).toBeNull()
  })

  it('on epoch_changed lets the host re-read the content once the new Y.Doc is in place', async () => {
    const first = endableProvider()
    const second = endableProvider()
    const token = vi.fn()
      .mockResolvedValueOnce(hocuspocusSession())
      .mockResolvedValueOnce(hocuspocusSession({ epoch: 1, room: 'vaulter.documents.7.42.1' }))
    const provider = vi.fn().mockReturnValueOnce(first.handle).mockReturnValueOnce(second.handle)
    let docAtCallback: YDoc | null = null
    let holder: { doc: { value: YDoc } } | null = null
    const onEpochChanged = vi.fn(() => { docAtCallback = holder!.doc.value })

    const { vm } = mountWith(
      { documents: { collaborationSession: token } },
      () => useCollaboration('42', { providers: { createHocuspocusProvider: provider }, onEpochChanged }),
    )
    holder = vm
    await vm.connect()
    const oldDoc = vm.doc.value

    first.end('epoch_changed')
    await flush()

    expect(onEpochChanged).toHaveBeenCalledOnce()
    expect(docAtCallback).toBe(vm.doc.value)
    expect(docAtCallback).not.toBe(oldDoc)
  })

  it('connect() during an epoch reopen leaves exactly one live provider (the reopen is discarded)', async () => {
    const first = endableProvider()
    const viaConnect = endableProvider()
    const viaReopen = endableProvider()
    let releaseReopen!: (session: CollaborationSession) => void
    const token = vi.fn()
      .mockResolvedValueOnce(hocuspocusSession())
      .mockReturnValueOnce(new Promise<CollaborationSession>((resolve) => { releaseReopen = resolve }))
      .mockResolvedValueOnce(hocuspocusSession({ room: 'vaulter.documents.7.42.2', epoch: 2 }))
    const provider = vi.fn()
      .mockReturnValueOnce(first.handle)
      .mockReturnValueOnce(viaConnect.handle)
      .mockReturnValueOnce(viaReopen.handle)

    const { vm } = mountWith(
      { documents: { collaborationSession: token } },
      () => useCollaboration('42', { providers: { createHocuspocusProvider: provider } }),
    )
    await vm.connect()

    first.end('epoch_changed')          // the reopen starts and waits for its session…
    await flush()
    await vm.connect()                  // …while the host connects again and wins
    releaseReopen(hocuspocusSession({ room: 'vaulter.documents.7.42.1', epoch: 1 }))
    await flush()

    expect(provider).toHaveBeenCalledTimes(2)
    expect(viaConnect.destroy).not.toHaveBeenCalled()
    expect(viaReopen.destroy).not.toHaveBeenCalled()
    expect(vm.handle.value).toBe(vm.handle.value)
    expect(vm.session.value?.room).toBe('vaulter.documents.7.42.2')
  })

  it('disconnect() during a reopen cancels it: no provider is opened afterwards', async () => {
    const first = endableProvider()
    let releaseReopen!: (session: CollaborationSession) => void
    const token = vi.fn()
      .mockResolvedValueOnce(hocuspocusSession())
      .mockReturnValueOnce(new Promise<CollaborationSession>((resolve) => { releaseReopen = resolve }))
    const provider = vi.fn().mockReturnValueOnce(first.handle).mockReturnValue(endableProvider().handle)

    const { vm } = mountWith(
      { documents: { collaborationSession: token } },
      () => useCollaboration('42', { providers: { createHocuspocusProvider: provider } }),
    )
    await vm.connect()

    first.end('token_expired')
    await flush()
    vm.disconnect()
    releaseReopen(hocuspocusSession())
    await flush()

    expect(provider).toHaveBeenCalledTimes(1)
    expect(vm.handle.value).toBeNull()
  })

  it('does not call onEpochChanged for a token renewal', async () => {
    const first = endableProvider()
    const token = vi.fn().mockResolvedValue(hocuspocusSession())
    const provider = vi.fn().mockReturnValueOnce(first.handle).mockReturnValue(endableProvider().handle)
    const onEpochChanged = vi.fn()

    const { vm } = mountWith(
      { documents: { collaborationSession: token } },
      () => useCollaboration('42', { providers: { createHocuspocusProvider: provider }, onEpochChanged }),
    )
    await vm.connect()

    first.end('token_expired')
    await flush()

    expect(onEpochChanged).not.toHaveBeenCalled()
  })
})

// ─── createCollaborationSession / fetchCollaborationSession ───────────────────

describe('createCollaborationSession', () => {
  const clientFor = (session: CollaborationSession) =>
    ({ documents: { collaborationSession: vi.fn().mockResolvedValue(session) } }) as unknown as VaulterClient

  it('requests the session of the node and opens the transport on a fresh Y.Doc', async () => {
    const client = clientFor(hocuspocusSession())
    const destroy = vi.fn()
    const factory = vi.fn().mockReturnValue({ awareness: null, isConnected: () => true, destroy })

    const handle = await createCollaborationSession(client, '42', {
      supportedSchemaVersion: 1,
      providers: { createHocuspocusProvider: factory },
    })

    expect(client.documents.collaborationSession).toHaveBeenCalledWith('42', { schemaVersion: 1 })
    expect(factory.mock.calls[0][0].document).toBe(handle.doc)
    expect(handle.readOnly).toBe(false)
    expect(handle.schemaOutdated).toBe(false)
    expect(handle.session.room).toBe('vaulter.documents.7.42.0')

    handle.destroy()
    expect(destroy).toHaveBeenCalledOnce()
  })

  it('opens a ro session read-only, so the editor must be editable: false', async () => {
    const factory = vi.fn().mockReturnValue({ awareness: null, destroy: vi.fn() })

    const handle = await createCollaborationSession(clientFor(hocuspocusSession({ mode: 'ro' })), '42', {
      providers: { createHocuspocusProvider: factory },
    })

    expect(handle.readOnly).toBe(true)
  })

  it('a server schema newer than the editor supports is read-only with a warning flag', async () => {
    const resolved = await fetchCollaborationSession(clientFor(hocuspocusSession({ schema_version: 4 })), '42', {
      supportedSchemaVersion: 3,
    })

    expect(resolved.schemaOutdated).toBe(true)
    expect(resolved.readOnly).toBe(true)
  })

  it('does not ask for a schema check when the editor declares no version', async () => {
    const client = clientFor(hocuspocusSession({ schema_version: 9 }))

    const resolved = await fetchCollaborationSession(client, '42')

    expect(client.documents.collaborationSession).toHaveBeenCalledWith('42', undefined)
    expect(resolved.schemaOutdated).toBe(false)
    expect(resolved.readOnly).toBe(false)
  })
})


// ─── connect() superseding a reopen ───────────────────────────────────────────

describe('useCollaboration — connect() that supersedes an epoch reopen', () => {
  it('opens the new room on a NEW Y.Doc and lets the host re-read the content', async () => {
    const first = endableProvider()
    const viaConnect = endableProvider()
    let releaseReopen!: (session: CollaborationSession) => void
    const token = vi.fn()
      .mockResolvedValueOnce(hocuspocusSession())
      .mockReturnValueOnce(new Promise<CollaborationSession>((resolve) => { releaseReopen = resolve }))
      .mockResolvedValueOnce(hocuspocusSession({ room: 'vaulter.documents.7.42.2', epoch: 2 }))
    const provider = vi.fn().mockReturnValueOnce(first.handle).mockReturnValueOnce(viaConnect.handle)
    const onEpochChanged = vi.fn()

    const { vm } = mountWith(
      { documents: { collaborationSession: token } },
      () => useCollaboration('42', { providers: { createHocuspocusProvider: provider }, onEpochChanged }),
    )
    await vm.connect()
    const oldDoc = vm.doc.value
    oldDoc.getText('t').insert(0, 'edit-before-restore')

    first.end('epoch_changed')
    await flush()
    await vm.connect()
    releaseReopen(hocuspocusSession({ room: 'vaulter.documents.7.42.1', epoch: 1 }))
    await flush()

    expect(vm.doc.value).not.toBe(oldDoc)
    expect(vm.doc.value.getText('t').toString()).toBe('')
    expect(provider.mock.calls[1][0].document).toBe(vm.doc.value)
    expect(onEpochChanged).toHaveBeenCalledOnce()
  })

  it('a superseded connect() rejects with a cancellation the caller can tell from a failure', async () => {
    let releaseFirst!: (session: CollaborationSession) => void
    const token = vi.fn()
      .mockReturnValueOnce(new Promise<CollaborationSession>((resolve) => { releaseFirst = resolve }))
      .mockResolvedValueOnce(hocuspocusSession())
    const provider = vi.fn().mockReturnValue(endableProvider().handle)
    const { vm } = mountWith(
      { documents: { collaborationSession: token } },
      () => useCollaboration('42', { providers: { createHocuspocusProvider: provider } }),
    )

    const older = vm.connect().catch((error: unknown) => error)
    await flush()
    const newer = vm.connect()
    releaseFirst(hocuspocusSession())
    await flush()
    await newer

    const outcome = await older
    expect(outcome).toBeInstanceOf(CollaborationCancelledError)
    expect(provider).toHaveBeenCalledTimes(1)
  })
})

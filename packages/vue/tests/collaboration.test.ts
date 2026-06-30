import { describe, expect, it, vi } from 'vitest'
import { Doc as YDoc } from 'yjs'
import { createCollaborationSession } from '../src/collaboration'
import type { CollaborationConnection } from '../src/types'

describe('createCollaborationSession', () => {
  it('builds a hocuspocus session with mapped params', () => {
    const destroy = vi.fn()
    const factory = vi.fn().mockReturnValue({ destroy })
    const doc = new YDoc()
    const connection: CollaborationConnection = {
      driver: 'hocuspocus',
      url: 'ws://localhost:1234',
      room: '42',
      token: 'tok',
    }

    const session = createCollaborationSession(connection, { doc, createHocuspocusProvider: factory })

    expect(session.transport).toBe('hocuspocus')
    expect(factory).toHaveBeenCalledWith({ url: 'ws://localhost:1234', name: '42', token: 'tok', document: doc })

    session.destroy()
    expect(destroy).toHaveBeenCalledOnce()
  })

  it('builds a reverb session through the injected factory', () => {
    const factory = vi.fn().mockReturnValue({ destroy: vi.fn() })
    const doc = new YDoc()
    const connection: CollaborationConnection = {
      driver: 'reverb',
      channel: 'vaulter.documents.1',
      document_id: 1,
      token: '',
    }

    const session = createCollaborationSession(connection, { doc, createReverbProvider: factory })

    expect(session.transport).toBe('reverb')
    expect(factory).toHaveBeenCalledWith({ channel: 'vaulter.documents.1', token: '', document: doc })
  })

  it('throws for the reverb driver without a provider factory', () => {
    const doc = new YDoc()
    const connection: CollaborationConnection = {
      driver: 'reverb',
      channel: 'vaulter.documents.1',
      document_id: 1,
      token: '',
    }

    expect(() => createCollaborationSession(connection, { doc })).toThrow(/reverb/)
  })
})

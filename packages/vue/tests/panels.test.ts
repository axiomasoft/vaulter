import { describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import VaulterGrantsPanel from '../src/components/VaulterGrantsPanel.vue'
import VaulterSharePanel from '../src/components/VaulterSharePanel.vue'
import type { Grant, MintedShare, Share } from '../src/api/types'
import { withClient } from './support/mount'

async function flushAll(): Promise<void> {
  for (let i = 0; i < 4; i += 1) {
    await Promise.resolve()
    await nextTick()
  }
}

describe('VaulterGrantsPanel', () => {
  function grant(partial: Partial<Grant> & Pick<Grant, 'id'>): Grant {
    return {
      node_id: '9', subject: { type: 'App\\User', id: '1' }, role: 'reader', role_version: 1,
      scope: 'item', expires_at: null, created_by: null, created_at: '2026-01-01T00:00:00Z', ...partial,
    }
  }

  it('lists grants, adds one with a subject object and revokes one by its id', async () => {
    const created = grant({ id: 'g2', subject: { type: 'App\\User', id: '42' }, role: 'writer' })
    const list = vi.fn()
      .mockResolvedValueOnce({ etag: '"node-access.9.0"', data: [grant({ id: 'g1' })] })
      .mockResolvedValueOnce({ etag: '"node-access.9.1"', data: [created, grant({ id: 'g1' })] })
    const create = vi.fn().mockResolvedValue(created)
    const revoke = vi.fn().mockResolvedValue(undefined)
    const wrapper = mount(VaulterGrantsPanel, {
      props: { nodeId: '9' },
      ...withClient({ grants: { listTagged: list, create, revoke } }),
    })

    await flushAll()
    expect(list).toHaveBeenCalledWith('9')
    expect(wrapper.findAll('.vaulter-grants-panel__item')).toHaveLength(1)

    // Fill the fallback subject inputs and submit.
    await wrapper.get('.vaulter-grants-panel__subject-type').setValue('App\\User')
    await wrapper.get('.vaulter-grants-panel__subject-id').setValue('42')
    await wrapper.get('.vaulter-grants-panel__role-select').setValue('writer')
    await wrapper.get('.vaulter-grants-panel__add').trigger('submit')
    await flushAll()

    expect(create).toHaveBeenCalledWith('9', expect.objectContaining({
      subject: { type: 'App\\User', id: '42' }, role: 'writer', scope: 'item',
    }))
    expect(wrapper.emitted('granted')?.[0]?.[0]).toMatchObject({ id: 'g2' })

    // Two rows now (new first); revoke the OLD grant by its id.
    const rows = wrapper.findAll('.vaulter-grants-panel__item')
    expect(rows).toHaveLength(2)
    await rows[1].get('.vaulter-grants-panel__revoke').trigger('click')
    await flushAll()

    // The grant made the access tag stale: the panel reloads and revokes with the fresh one.
    expect(revoke).toHaveBeenCalledWith('9', 'g1', { etag: '"node-access.9.1"' })
    expect(wrapper.emitted('revoked')?.[0]?.[0]).toMatchObject({ id: 'g1' })
    expect(wrapper.findAll('.vaulter-grants-panel__item')).toHaveLength(1)
  })

  it('lets the host resolve the subject before it is granted', async () => {
    const create = vi.fn().mockResolvedValue(grant({ id: 'g3' }))
    const resolveSubject = vi.fn().mockResolvedValue({ type: 'team', id: 7 })
    const wrapper = mount(VaulterGrantsPanel, {
      props: { nodeId: '9', autoLoad: false, resolveSubject },
      ...withClient({ grants: { create } }),
    })

    await wrapper.get('.vaulter-grants-panel__subject-type').setValue('team')
    await wrapper.get('.vaulter-grants-panel__subject-id').setValue('seven')
    await wrapper.get('.vaulter-grants-panel__add').trigger('submit')
    await flushAll()

    expect(resolveSubject).toHaveBeenCalledWith('team', 'seven')
    expect(create.mock.calls[0][1].subject).toEqual({ type: 'team', id: 7 })
  })

  it('shows a load failure without an unhandled rejection', async () => {
    const wrapper = mount(VaulterGrantsPanel, {
      props: { nodeId: '9' },
      ...withClient({ grants: { listTagged: vi.fn().mockRejectedValue(new Error('boom')) } }),
    })
    await flushAll()

    expect(wrapper.get('.vaulter-grants-panel__error').text()).toBe('Could not load grants.')
  })
})

describe('VaulterSharePanel', () => {
  function share(partial: Partial<Share> & Pick<Share, 'id'>): Share {
    return {
      node_id: '9', role: 'reader', role_version: 1, audience: 'anyone', scope: 'item', expires_at: null,
      max_uses: null, use_count: 0, has_password: false, token_generation: 1, version: 0, etag: `"share.${partial.id}.0"`,
      created_by: null, created_at: '2026-01-01T00:00:00Z', ...partial,
    }
  }

  it.each(['previewer', 'reader', 'commenter', 'writer'] as const)('sends the %s role and the chosen audience, never an ability mask', async (role) => {
    const create = vi.fn().mockResolvedValue({ ...share({ id: '5' }), token: 'token', url: 'https://x/share/token' })
    const wrapper = mount(VaulterSharePanel, {
      props: { nodeId: '9', autoLoad: false, roles: [role], audiences: ['authenticated'] },
      ...withClient({ shares: { create } }),
    })

    await wrapper.get('.vaulter-share-panel__create').trigger('submit')
    await flushAll()

    expect(create).toHaveBeenCalledWith('9', expect.objectContaining({ role, audience: 'authenticated' }))
    expect(create.mock.calls[0][1]).not.toHaveProperty('permissions')
    expect(create.mock.calls[0][1]).not.toHaveProperty('requireLogin')
  })

  it('rotates a link from the list and shows the new token once', async () => {
    const rotated: MintedShare = { ...share({ id: '1', token_generation: 2 }), token: 'new.SECRET', url: 'https://x/share/new.SECRET' }
    const rotate = vi.fn().mockResolvedValue(rotated)
    const wrapper = mount(VaulterSharePanel, {
      props: { nodeId: '9' },
      ...withClient({ shares: { list: vi.fn().mockResolvedValue([share({ id: '1' })]), rotate } }),
    })
    await flushAll()

    await wrapper.get('.vaulter-share-panel__rotate').trigger('click')
    await flushAll()

    expect(rotate).toHaveBeenCalledWith('9', '1', { etag: '"share.1.0"' })
    expect((wrapper.get('.vaulter-share-panel__token').element as HTMLInputElement).value).toBe('https://x/share/new.SECRET')
    expect(wrapper.emitted('rotated')?.[0]?.[0]).toMatchObject({ token: 'new.SECRET' })
    expect(wrapper.get('.vaulter-share-panel__list').html()).not.toContain('SECRET')
  })

  it('shows the one-time token on create and never renders a stored raw token in the list', async () => {
    const created: MintedShare = {
      ...share({ id: '5', has_password: true, max_uses: 3 }),
      token: 'abc.SUPERSECRET',
      url: 'https://x/share/abc.SUPERSECRET/download',
    }
    const list = vi.fn().mockResolvedValue([share({ id: '1' })])
    const create = vi.fn().mockResolvedValue(created)
    const wrapper = mount(VaulterSharePanel, {
      props: { nodeId: '9' },
      ...withClient({ shares: { list, create }, publicShares: { downloadUrl: vi.fn() } }),
    })
    await flushAll()

    expect(wrapper.get('.vaulter-share-panel__list').html()).not.toContain('SUPERSECRET')

    // Create → the one-time token surfaces in the created banner.
    await wrapper.get('.vaulter-share-panel__create').trigger('submit')
    await flushAll()

    const banner = wrapper.get('.vaulter-share-panel__created')
    expect((banner.get('.vaulter-share-panel__token').element as HTMLInputElement).value).toBe('https://x/share/abc.SUPERSECRET/download')
    expect(wrapper.emitted('created')?.[0]?.[0]).toMatchObject({ token: 'abc.SUPERSECRET' })

    // The list shows the new link's settings, never its token.
    const listHtml = wrapper.get('.vaulter-share-panel__list').html()
    expect(listHtml).not.toContain('SUPERSECRET')
    expect(listHtml).toContain('password')
    expect(listHtml).toContain('0 / 3 uses')
  })

  it('revokes a link and removes its row', async () => {
    const revoke = vi.fn().mockResolvedValue(undefined)
    const wrapper = mount(VaulterSharePanel, {
      props: { nodeId: '9' },
      ...withClient({ shares: { list: vi.fn().mockResolvedValue([share({ id: '1' })]), revoke } }),
    })
    await flushAll()

    await wrapper.get('.vaulter-share-panel__revoke').trigger('click')
    await flushAll()

    expect(revoke).toHaveBeenCalledWith('9', '1', { etag: '"share.1.0"' })
    expect(wrapper.emitted('revoked')?.[0]).toEqual(['1'])
    expect(wrapper.findAll('.vaulter-share-panel__item')).toHaveLength(0)
  })
})

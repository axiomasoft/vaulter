import { describe, expect, it } from 'vitest'
import { createVaulterClient } from '../../src/api/client'
import { useGrants } from '../../src/composables/useGrants'
import { useShares } from '../../src/composables/useShares'
import { call, fetchSequence, jsonResponse, problemResponse } from '../support/http'
import { mountWith } from '../support/mount'

const meta = (next: string | null) => ({ next_cursor: next, per_page: 1 })
const grantPage = (etag: string, ids: string[], next: string | null) => () => jsonResponse({ etag, data: ids.map((id) => ({ id })), meta: meta(next) })
const sharePage = (ids: string[], next: string | null) => () => jsonResponse({ data: ids.map((id) => ({ id })), meta: meta(next) })
const stale = () => problemResponse({ code: 'permission_denied', status: 403, title: 'Forbidden' }, 403)

describe('useGrants over a paged server', () => {
  it('publishes the whole set and the access tag only after the last page, through an empty page with a cursor', async () => {
    const fetchMock = fetchSequence(grantPage('"a.1"', ['g1'], 'c1'), grantPage('"a.1"', [], 'c2'), grantPage('"a.1"', ['g2'], null))
    const { vm } = mountWith(createVaulterClient({ fetch: fetchMock }), () => useGrants('5'))

    await vm.list()

    expect(vm.grants.value.map((g) => g.id)).toEqual(['g1', 'g2'])
    expect(vm.accessTag.value).toBe('"a.1"')
    expect(call(fetchMock, 1).url).toBe('/api/vaulter/v1/nodes/5/grants?cursor=c1')
    expect(call(fetchMock, 2).url).toBe('/api/vaulter/v1/nodes/5/grants?cursor=c2')
  })

  it('keeps no partial set or tag when a later page fails, the tag drifts or a cursor repeats', async () => {
    for (const answers of [
      [grantPage('"a.1"', ['g1'], 'c1'), stale],
      [grantPage('"a.1"', ['g1'], 'c1'), grantPage('"a.2"', ['g2'], null)],
      [grantPage('"a.1"', ['g1'], 'c1'), grantPage('"a.1"', ['g2'], 'c1')],
    ]) {
      const { vm } = mountWith(createVaulterClient({ fetch: fetchSequence(...answers) }), () => useGrants('5'))

      await expect(vm.list()).rejects.toBeTruthy()

      expect(vm.grants.value).toEqual([])
      expect(vm.accessTag.value).toBeNull()
      expect(vm.error.value).toBeTruthy()
    }
  })
})

describe('useShares over a paged server', () => {
  it('reads every page, an empty one with a cursor included', async () => {
    const fetchMock = fetchSequence(sharePage(['s1'], 'c1'), sharePage([], 'c2'), sharePage(['s2'], null))
    const { vm } = mountWith(createVaulterClient({ fetch: fetchMock }), () => useShares('5'))

    await vm.list()

    expect(vm.shares.value.map((s) => s.id)).toEqual(['s1', 's2'])
    expect(call(fetchMock, 2).url).toBe('/api/vaulter/v1/nodes/5/shares?cursor=c2')
  })

  it('shows no partial list when a page fails or a cursor repeats', async () => {
    for (const answers of [[sharePage(['s1'], 'c1'), stale], [sharePage(['s1'], 'c1'), sharePage(['s2'], 'c1')]]) {
      const { vm } = mountWith(createVaulterClient({ fetch: fetchSequence(...answers) }), () => useShares('5'))

      await expect(vm.list()).rejects.toBeTruthy()

      expect(vm.shares.value).toEqual([])
    }
  })
})

describe('the page helpers of the client', () => {
  it('forwards the cursor and per_page and returns one page with its meta and tag', async () => {
    const fetchMock = fetchSequence(grantPage('"a.1"', ['g1'], 'next'), sharePage(['s1'], null))
    const client = createVaulterClient({ fetch: fetchMock })

    const grants = await client.grants.page('5', { cursor: 'c0', perPage: 1 })
    const shares = await client.shares.page('5', { perPage: 200 })

    expect(grants).toMatchObject({ etag: '"a.1"', meta: { next_cursor: 'next' } })
    expect(shares.meta.next_cursor).toBeNull()
    expect(call(fetchMock, 0).url).toBe('/api/vaulter/v1/nodes/5/grants?cursor=c0&per_page=1')
    expect(call(fetchMock, 1).url).toBe('/api/vaulter/v1/nodes/5/shares?per_page=200')
  })
})

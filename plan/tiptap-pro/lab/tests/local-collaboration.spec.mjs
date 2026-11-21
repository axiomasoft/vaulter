import { test, expect } from '@playwright/test'

async function connect(page, room, options = {}) {
  await page.goto(`/?mode=local&room=${room}`)
  await page.waitForFunction(() => Boolean(window.lab))
  await page.evaluate(input => window.lab.connect(input), { mode: 'local', room, ...options })
  await expect.poll(() => page.evaluate(() => window.lab.states.every(state => state.authenticated && state.synced && state.status === 'connected'))).toBe(true)
}

test('local OSS: independent contexts, offline merge, fresh-load recovery', async ({ browser }) => {
  const room = `tiptap-pro-lab-smoke-${crypto.randomUUID()}`
  const contextA = await browser.newContext()
  const contextB = await browser.newContext()
  const pageA = await contextA.newPage()
  const pageB = await contextB.newPage()
  try {
    await connect(pageA, room)
    await connect(pageB, room)
    await pageA.evaluate(() => window.lab.setText(0, 'Baseline'))
    await expect.poll(() => pageB.evaluate(() => window.lab.snapshot().text[0])).toBe('Baseline')
    await pageB.evaluate(() => { window.lab.disconnect(0); window.lab.disconnect(1) })
    await expect.poll(() => pageB.evaluate(() => window.lab.states.every(state => state.status !== 'connected'))).toBe(true)
    await pageB.evaluate(() => window.lab.appendText(0, ' offline'))
    await pageA.evaluate(() => window.lab.appendText(0, ' online'))
    await pageB.evaluate(() => { window.lab.reconnect(0); window.lab.reconnect(1) })
    await expect.poll(() => pageB.evaluate(() => window.lab.states.every(state => state.status === 'connected' && state.synced))).toBe(true)
    await expect.poll(async () => {
      const a = await pageA.evaluate(() => window.lab.snapshot().text)
      const b = await pageB.evaluate(() => window.lab.snapshot().text)
      return a.every(text => text.includes('offline') && text.includes('online')) && b.every(text => text === a[0])
    }).toBe(true)
    const merged = await pageA.evaluate(() => window.lab.snapshot().text[0])
    await expect.poll(() => pageA.evaluate(() => window.lab.states.every(state => state.unsynced === 0))).toBe(true)
    await contextA.close()
    await contextB.close()
    const freshContext = await browser.newContext()
    try {
      const freshPage = await freshContext.newPage()
      await connect(freshPage, room)
      await expect.poll(() => freshPage.evaluate(() => window.lab.snapshot().text[0])).toBe(merged)
      await expect.poll(() => freshPage.evaluate(() => window.lab.snapshot().text[1])).toBe(merged)
    } finally { await freshContext.close() }
  } finally {
    await contextA.close()
    await contextB.close()
  }
})

test('local token is room-scoped and public config contains no signing material', async ({ request }) => {
  const publicConfig = await request.get('/api/config')
  expect(publicConfig.ok()).toBe(true)
  const body = await publicConfig.text()
  expect(body).not.toContain('PRIVATE KEY')
  expect(body).not.toContain('TIPTAP_PRO_TOKEN')
  const denied = await request.post('/api/token', { data: { mode: 'local', room: 'production-document' } })
  expect(denied.status()).toBe(400)
  const room = `tiptap-pro-lab-token-${crypto.randomUUID()}`
  const response = await request.post('/api/token', { data: { mode: 'local', room, user: 'test-user' } })
  expect(response.ok()).toBe(true)
  const token = (await response.json()).token
  const payload = JSON.parse(Buffer.from(token.split('.')[1], 'base64url').toString('utf8'))
  expect(payload.iss).toBe('tiptap-pro-lab-local')
  expect(payload.aud).toEqual(['Documents'])
  expect(payload.permissions).toEqual([{ action: 'Documents:Write', resource: room }])
  expect(payload.exp - payload.iat).toBe(600)
})

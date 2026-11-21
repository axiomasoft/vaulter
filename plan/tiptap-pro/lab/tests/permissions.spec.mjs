import { test, expect } from '@playwright/test'
import { decodeFrame } from '../../scripts/decode-frame.mjs'

const mode = process.env.TIPTAP_PERMISSION_MODE || 'local'
if (!['local', 'cloud'].includes(mode)) throw new Error('Unknown TIPTAP_PERMISSION_MODE')

async function connect(page, room) {
  await page.goto('/')
  await page.waitForFunction(() => Boolean(window.lab))
  await page.evaluate(input => window.lab.connect(input), { mode, room, features: [] })
  await expect.poll(() => page.evaluate(() => window.lab.states.length === 2 && window.lab.states.every(
    state => state.authenticated && state.synced && state.status === 'connected',
  ))).toBe(true)
}

async function addProbe(page, { tokenRoom, documentRoom, readOnly }) {
  await page.evaluate(async ({ tokenRoom, documentRoom, readOnly, mode }) => {
    const config = await fetch('/api/config').then(response => response.json())
    const Doc = window.lab.docs[0].constructor
    const Provider = window.lab.providers[0].constructor
    const doc = new Doc()
    const probe = { doc, synced: false, scope: null, rejected: false }
    window.permissionProbe = probe
    probe.provider = new Provider({
      ...(mode === 'cloud' ? { appId: config.appId } : { url: config.localUrl }),
      document: doc,
      name: documentRoom,
      token: async () => {
        const response = await fetch('/api/token', {
          method: 'POST', headers: { 'content-type': 'application/json' },
          body: JSON.stringify({ mode, room: tokenRoom, user: 'permission-probe', readOnly }),
        })
        if (!response.ok) throw new Error('Cannot issue probe token')
        return (await response.json()).token
      },
      onSynced({ state }) { probe.synced = state },
      onAuthenticated({ scope }) { probe.scope = scope },
      onAuthenticationFailed() { probe.rejected = true; queueMicrotask(() => probe.provider.disconnect()) },
    })
  }, { tokenRoom, documentRoom, readOnly, mode })
}

test(`${mode}: backend rejects read-only writes even when the editor UI is bypassed`, async ({ page }) => {
  const room = `tiptap-pro-lab-permission-${crypto.randomUUID()}`
  const statuses = []
  page.on('websocket', socket => socket.on('framereceived', ({ payload }) => {
    const frame = decodeFrame(Buffer.isBuffer(payload) ? payload : Buffer.from(payload))
    if (frame.type === 8) statuses.push(frame.applied)
  }))
  try {
    await connect(page, room)
    await page.evaluate(() => window.lab.docs[0].getMap('permissionProbe').set('allowed', 'writer'))
    await addProbe(page, { tokenRoom: room, documentRoom: room, readOnly: true })
    await expect.poll(() => page.evaluate(() => window.permissionProbe.synced)).toBe(true)
    await expect.poll(() => page.evaluate(() => window.permissionProbe.doc.getMap('permissionProbe').get('allowed'))).toBe('writer')
    await page.evaluate(() => window.permissionProbe.doc.getMap('permissionProbe').set('denied', 'reader-attempt'))
    await expect.poll(() => statuses.includes(false)).toBe(true)
    expect(await page.evaluate(() => window.lab.docs.map(doc => doc.getMap('permissionProbe').has('denied')))).toEqual([false, false])
    // A second valid writer operation is delivered to the read-only connection.
    await page.evaluate(() => window.lab.docs[0].getMap('permissionProbe').set('afterDenial', 'writer-still-works'))
    await expect.poll(() => page.evaluate(() => window.permissionProbe.doc.getMap('permissionProbe').get('afterDenial'))).toBe('writer-still-works')
    expect(await page.evaluate(() => window.permissionProbe.doc.getMap('permissionProbe').get('denied'))).toBe('reader-attempt')
    await page.evaluate(() => { window.permissionProbe.provider.destroy(); window.permissionProbe.doc.destroy() })
    await addProbe(page, { tokenRoom: room, documentRoom: room, readOnly: true })
    await expect.poll(() => page.evaluate(() => window.permissionProbe.synced)).toBe(true)
    expect(await page.evaluate(() => window.permissionProbe.doc.getMap('permissionProbe').has('denied'))).toBe(false)
  } finally {
    await page.evaluate(() => { window.permissionProbe?.provider.destroy(); window.permissionProbe?.doc.destroy() })
  }
})

test(`${mode}: a token for one room cannot authenticate a different room`, async ({ page }) => {
  const tokenRoom = `tiptap-pro-lab-scope-${crypto.randomUUID()}`
  const documentRoom = `tiptap-pro-lab-scope-${crypto.randomUUID()}`
  try {
    await connect(page, tokenRoom)
    await addProbe(page, { tokenRoom, documentRoom, readOnly: false })
    await expect.poll(() => page.evaluate(() => window.permissionProbe.rejected)).toBe(true)
    expect(await page.evaluate(() => window.permissionProbe.synced)).toBe(false)
  } finally {
    await page.evaluate(() => { window.permissionProbe?.provider.destroy(); window.permissionProbe?.doc.destroy() })
  }
})

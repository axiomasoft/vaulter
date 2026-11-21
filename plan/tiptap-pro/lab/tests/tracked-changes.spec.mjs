import { test, expect } from '@playwright/test'

test('installed Tracked Changes: remote reject and accept converge over local OSS transport', async ({ page }) => {
  const room = `tiptap-pro-lab-tracked-${crypto.randomUUID()}`
  await page.goto('/?mode=local')
  await page.waitForFunction(() => Boolean(window.lab))
  await page.evaluate(room => window.lab.connect({ mode: 'local', room, features: ['trackedChanges'] }), room)
  await expect.poll(() => page.evaluate(() => window.lab.states.every(state => state.authenticated && state.synced))).toBe(true)
  await page.evaluate(() => window.lab.setText(0, 'Baseline'))
  await expect.poll(() => page.evaluate(() => window.lab.snapshot().text[1])).toBe('Baseline')

  await page.evaluate(() => window.lab.trackedInsert(0, window.lab.editors[0].state.doc.content.size - 1, ' rejected'))
  await expect.poll(() => page.evaluate(() => window.lab.suggestions(1).length)).toBe(1)
  const rejectedId = await page.evaluate(() => window.lab.suggestions(1)[0].id)
  expect(await page.evaluate(id => window.lab.reject(1, id), rejectedId)).toBe(true)
  await expect.poll(() => page.evaluate(() => window.lab.snapshot().text)).toEqual(['Baseline', 'Baseline'])
  await expect.poll(() => page.evaluate(() => window.lab.suggestions(0).length)).toBe(0)

  await page.evaluate(() => window.lab.trackedInsert(0, window.lab.editors[0].state.doc.content.size - 1, ' accepted'))
  await expect.poll(() => page.evaluate(() => window.lab.suggestions(1).length)).toBe(1)
  const acceptedId = await page.evaluate(() => window.lab.suggestions(1)[0].id)
  expect(await page.evaluate(id => window.lab.accept(1, id), acceptedId)).toBe(true)
  await expect.poll(() => page.evaluate(() => window.lab.snapshot().text)).toEqual(['Baseline accepted', 'Baseline accepted'])
  await expect.poll(() => page.evaluate(() => window.lab.suggestions(0).length)).toBe(0)
})

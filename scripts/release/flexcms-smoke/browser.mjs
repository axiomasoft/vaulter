import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { chromium } from 'playwright'

const [origin, fixtureFile] = process.argv.slice(2)
const fixture = JSON.parse(await readFile(fixtureFile, 'utf8'))
const api = origin + '/api/vaulter/v1/documents/' + fixture.id + '/content'
const url = new URL('/browser-probe/index.html', origin)
url.searchParams.set('id', fixture.id)
const browser = await chromium.launch({ executablePath: process.env.BROWSER_BIN || undefined })

async function contextFor(email) {
  const credentials = Buffer.from(email + ':' + fixture.password).toString('base64')
  return browser.newContext({ extraHTTPHeaders: { Authorization: 'Basic ' + credentials } })
}

async function open(page) {
  const [response] = await Promise.all([
    page.waitForResponse((r) => r.url() === api && r.request().method() === 'GET'),
    page.goto(url.href),
  ])
  assert.equal(response.status(), 200)
  await page.locator('.tiptap').waitFor()
  return response.headerValue('etag')
}

async function typeText(page, text) {
  const editor = page.locator('.tiptap')
  await editor.click()
  await editor.press('ControlOrMeta+a')
  const [response] = await Promise.all([
    page.waitForResponse((r) => r.url() === api && r.request().method() === 'PUT'),
    page.keyboard.insertText(text),
  ])
  return response
}

try {
  const owner = await contextFor(fixture.owner)
  const reader = await contextFor(fixture.reader)
  const first = await owner.newPage()
  const second = await owner.newPage()
  const readOnly = await reader.newPage()
  const [tagA, tagB] = await Promise.all([open(first), open(second)])
  assert.ok(tagA)
  assert.equal(tagB, tagA)

  const text = 'Первая сохранённая правка'
  const draft = 'Черновик из второй вкладки'
  const saved = await typeText(first, text)
  assert.equal(saved.status(), 200)
  assert.equal(await saved.request().headerValue('if-match'), tagA)
  assert.equal(saved.request().postDataJSON().content.content[0].content[0].text, text)
  const nextTag = await saved.headerValue('etag')
  assert.ok(nextTag)
  assert.notEqual(nextTag, tagA)

  const conflict = await typeText(second, draft)
  assert.equal(conflict.status(), 412)
  assert.equal((await conflict.json()).code, 'document_state_mismatch')
  assert.equal(await conflict.request().headerValue('if-match'), tagB)
  await second.getByRole('alert').waitFor()
  assert.equal(await second.locator('.tiptap').innerText(), draft)

  await first.reload()
  await first.locator('.tiptap').waitFor()
  assert.equal(await first.locator('.tiptap').innerText(), text)
  assert.equal(await open(readOnly), nextTag)
  assert.equal(await readOnly.locator('.tiptap').getAttribute('contenteditable'), 'false')

  const denied = await reader.request.put(api, {
    headers: { 'If-Match': nextTag, Accept: 'application/json' },
    data: { content: { type: 'doc', content: [{ type: 'paragraph' }] } },
  })
  assert.equal(denied.status(), 403)
  assert.equal((await denied.json()).code, 'permission_denied')

  const current = await owner.request.get(api)
  assert.equal(current.status(), 200)
  assert.equal(current.headers().etag, nextTag)
  assert.equal((await current.json()).data.content.content[0].content[0].text, text)
  console.log('CMS-BROWSER-PASS', JSON.stringify({
    chromium: browser.version(), save: saved.status(), stale: conflict.status(), readerWrite: denied.status(),
  }))
} finally {
  await browser.close()
}

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test, type Page } from '@playwright/test'

interface PmNode {
  type?: string
  text?: string
  attrs?: Record<string, unknown>
  marks?: PmNode[]
  content?: PmNode[]
}

interface Fixture {
  driveId: string
  owner: string
  reader: string
  password: string
  richText: { id: string, name: string }
  markdown: { id: string, name: string }
}

const origin = process.env.ORIGIN ?? ''
const fixtureFile = process.env.FIXTURE_FILE ?? ''
const fixturesDir = process.env.FIXTURES_DIR ?? new URL('../fixtures/', import.meta.url).pathname
assert.ok(origin, 'ORIGIN is required')
assert.ok(fixtureFile, 'FIXTURE_FILE is required')

const fixture = JSON.parse(readFileSync(fixtureFile, 'utf8')) as Fixture
const richSource = JSON.parse(readFileSync(`${fixturesDir}/rich-text.json`, 'utf8')) as PmNode
const markdownSource = JSON.parse(readFileSync(`${fixturesDir}/markdown.json`, 'utf8')) as PmNode

function basic(email: string): string {
  return 'Basic ' + Buffer.from(email + ':' + fixture.password).toString('base64')
}

function blank(value: unknown): boolean {
  return value === null || value === undefined || value === ''
}

function containsStructure(actual: PmNode, expected: PmNode, path = 'doc'): void {
  assert.equal(actual.type, expected.type, path)
  if (expected.text !== undefined) {
    assert.equal(actual.text, expected.text, path)
  }
  for (const [key, value] of Object.entries(expected.attrs ?? {})) {
    if (blank(value)) {
      continue
    }
    assert.deepEqual(actual.attrs?.[key], value, `${path}.${expected.type}.${key}`)
  }
  for (const mark of expected.marks ?? []) {
    const found = (actual.marks ?? []).find((candidate) => candidate.type === mark.type)
    assert.ok(found, `${path} missing mark ${mark.type}`)
    containsStructure(found, mark, `${path}/mark:${mark.type}`)
  }
  const expectedChildren = expected.content ?? []
  const actualChildren = actual.content ?? []
  assert.equal(actualChildren.length, expectedChildren.length, `${path} ${expected.type} children`)
  expectedChildren.forEach((child, index) => {
    containsStructure(actualChildren[index], child, `${path}/${child.type ?? index}:${index}`)
  })
}

function collectTypes(node: PmNode, found: string[] = []): string[] {
  if (node.type) {
    found.push(node.type)
  }
  for (const mark of node.marks ?? []) {
    collectTypes(mark, found)
  }
  for (const child of node.content ?? []) {
    collectTypes(child, found)
  }
  return found
}

async function editorJson(page: Page): Promise<PmNode> {
  return page.evaluate(() => {
    const hook = (window as Window & { __documentsBasic?: { getJSON: () => PmNode | null } }).__documentsBasic
    if (!hook) {
      throw new Error('editor hook missing')
    }
    const json = hook.getJSON()
    if (!json) {
      throw new Error('editor json missing')
    }
    return json
  })
}

async function openDocument(page: Page, id: string): Promise<string | null> {
  await page.goto(`${origin}/?drive=${fixture.driveId}`)
  const row = page.locator(`[data-node-id="${id}"]`)
  await row.waitFor()
  const api = `${origin}/api/vaulter/v1/documents/${id}/content`
  const [response] = await Promise.all([
    page.waitForResponse(
      (candidate) => candidate.url() === api && candidate.request().method() === 'GET',
      { timeout: 20_000 },
    ),
    row.locator('.vaulter-node-list__name').dblclick(),
  ])
  assert.equal(response.status(), 200)
  await page.locator('[data-editor-ready="true"]').waitFor()
  await page.locator('.tiptap').waitFor()
  return response.headerValue('etag')
}

async function reopen(page: Page, id: string): Promise<void> {
  await page.getByRole('button', { name: 'Back to list' }).click()
  const row = page.locator(`[data-node-id="${id}"]`)
  await row.waitFor()
  await row.locator('.vaulter-node-list__name').dblclick()
  await page.locator('[data-editor-ready="true"]').waitFor()
}

async function save(page: Page, id: string) {
  const api = `${origin}/api/vaulter/v1/documents/${id}/content`
  const [response] = await Promise.all([
    page.waitForResponse((candidate) => candidate.url() === api && candidate.request().method() === 'PUT'),
    page.getByRole('button', { name: 'Save' }).click(),
  ])
  if (response.status() !== 200 && response.status() !== 412) {
    assert.fail(`PUT ${response.status()} ${await response.text()}`)
  }
  return response
}

async function replaceAndSave(page: Page, id: string, text: string) {
  const editor = page.locator('.tiptap')
  await editor.click()
  await editor.press('ControlOrMeta+a')
  await page.keyboard.insertText(text)
  return save(page, id)
}

test('drive opens both types, round-trips marks and attrs, and keeps the 412 draft', async ({ browser }) => {
  const owner = await browser.newContext({ extraHTTPHeaders: { Authorization: basic(fixture.owner) } })
  const reader = await browser.newContext({ extraHTTPHeaders: { Authorization: basic(fixture.reader) } })
  try {
    const richPage = await owner.newPage()
    const richTag = await openDocument(richPage, fixture.richText.id)
    assert.ok(richTag)
    assert.equal(await richPage.locator('.tiptap-editor[data-preset="rich-text"]').count(), 1)
    const richBefore = await editorJson(richPage)
    containsStructure(richBefore, richSource)
    const richSaved = await save(richPage, fixture.richText.id)
    assert.equal(richSaved.status(), 200)
    assert.notEqual(await richSaved.headerValue('etag'), richTag)
    await reopen(richPage, fixture.richText.id)
    const richAfter = await editorJson(richPage)
    assert.deepEqual(richAfter, richBefore)
    containsStructure(richAfter, richSource)

    const markdownPage = await owner.newPage()
    const markdownTag = await openDocument(markdownPage, fixture.markdown.id)
    assert.ok(markdownTag)
    assert.equal(await markdownPage.locator('.tiptap-editor[data-preset="markdown"]').count(), 1)
    const markdownBefore = await editorJson(markdownPage)
    containsStructure(markdownBefore, markdownSource)
    const markdownTypes = collectTypes(markdownBefore)
    for (const banned of ['image', 'table', 'tableRow', 'tableCell', 'tableHeader', 'underline']) {
      assert.equal(markdownTypes.includes(banned), false, banned)
    }
    const markdownSaved = await save(markdownPage, fixture.markdown.id)
    assert.equal(markdownSaved.status(), 200)
    await reopen(markdownPage, fixture.markdown.id)
    const markdownAfter = await editorJson(markdownPage)
    assert.deepEqual(markdownAfter, markdownBefore)
    containsStructure(markdownAfter, markdownSource)

    const first = await owner.newPage()
    const second = await owner.newPage()
    const readOnly = await reader.newPage()
    const [tagA, tagB] = await Promise.all([
      openDocument(first, fixture.richText.id),
      openDocument(second, fixture.richText.id),
    ])
    assert.ok(tagA)
    assert.equal(tagB, tagA)

    const text = 'Первая сохранённая правка'
    const draft = 'Черновик из второй вкладки'
    const saved = await replaceAndSave(first, fixture.richText.id, text)
    assert.equal(saved.status(), 200)
    assert.equal(await saved.request().headerValue('if-match'), tagA)
    const nextTag = await saved.headerValue('etag')
    assert.ok(nextTag)
    assert.notEqual(nextTag, tagA)

    const conflict = await replaceAndSave(second, fixture.richText.id, draft)
    assert.equal(conflict.status(), 412)
    assert.equal((await conflict.json()).code, 'document_state_mismatch')
    assert.equal(await conflict.request().headerValue('if-match'), tagB)
    await second.getByRole('alert').waitFor()
    assert.equal((await second.locator('.tiptap').innerText()).trim(), draft)

    await reopen(first, fixture.richText.id)
    assert.equal((await first.locator('.tiptap').innerText()).trim(), text)

    const readerTag = await openDocument(readOnly, fixture.richText.id)
    assert.equal(readerTag, nextTag)
    assert.equal(await readOnly.locator('.tiptap').getAttribute('contenteditable'), 'false')
    assert.equal((await readOnly.locator('.tiptap').innerText()).trim(), text)
    const beforeDenied = await readOnly.locator('.tiptap').innerText()
    await readOnly.locator('.tiptap').click({ force: true })
    await readOnly.keyboard.insertText('reader-edit')
    assert.equal(await readOnly.locator('.tiptap').innerText(), beforeDenied)
    assert.equal(await readOnly.getByRole('button', { name: 'Save' }).isDisabled(), true)

    const api = `${origin}/api/vaulter/v1/documents/${fixture.richText.id}/content`
    const denied = await reader.request.put(api, {
      headers: { 'If-Match': nextTag ?? '', Accept: 'application/json' },
      data: { content: { type: 'doc', content: [{ type: 'paragraph' }] } },
    })
    assert.equal(denied.status(), 403)
    assert.equal((await denied.json()).code, 'permission_denied')

    const current = await owner.request.get(api)
    assert.equal(current.status(), 200)
    assert.equal(current.headers().etag, nextTag)
    const stored = (await current.json()).data.content as PmNode
    assert.equal(stored.content?.[0]?.content?.[0]?.text, text)

    console.log('DOCUMENTS-BASIC-PASS', JSON.stringify({
      chromium: browser.version(),
      save: saved.status(),
      stale: conflict.status(),
      readerWrite: denied.status(),
    }))
  } finally {
    await owner.close()
    await reader.close()
  }
})

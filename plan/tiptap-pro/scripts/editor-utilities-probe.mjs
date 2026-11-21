import { chromium } from '../lab/node_modules/playwright/index.mjs'
import { createServer } from '../lab/node_modules/vite/dist/node/index.js'
import { writeFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { sanitize } from './decode-frame.mjs'

const lab = fileURLToPath(new URL('../lab/', import.meta.url))
const output = new URL('../results/editor-utilities.json', import.meta.url)
const evidence = { startedAt: new Date().toISOString(), externalRequests: 0, cases: [] }
const fixture = String.raw`
import { Editor } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import { Markdown } from '@tiptap/markdown'
import { ExportMarkdown } from '@tiptap-pro/extension-export-markdown'
import { PasteHandler, transformPastedHTML } from '@tiptap-pro/extension-paste-handler'
import { PageKit } from '@tiptap-pro/extension-pages-pagekit'
import { ConvertKit } from '@tiptap-pro/extension-convert-kit'
import { ExportDocx } from '@tiptap-pro/extension-export-docx'
import { ExportDoc } from '@tiptap-pro/extension-export-doc'
import { ExportPdf } from '@tiptap-pro/extension-export-pdf'
import { ExportOdt } from '@tiptap-pro/extension-export-odt'
import { ExportEpub } from '@tiptap-pro/extension-export-epub'

window.utilities = async () => {
  const cases = []
  const mount = () => document.body.appendChild(document.createElement('div'))
  const run = async (name, action) => {
    const result = { name, checks: {} }
    cases.push(result)
    const verify = (key, value) => {
      result.checks[key] = Boolean(value)
      if (!value) throw new Error('Failed: ' + key)
    }
    try { await action(verify, result); result.passed = true }
    catch (error) { result.passed = false; result.error = error.stack?.split('\n').slice(0, 5).join('\n') || error.message }
  }
  await run('paste-through-editor', (verify, result) => {
    const editor = new Editor({ element: mount(), extensions: [StarterKit, PasteHandler] })
    try {
      const input = '<p role="heading" aria-level="2">Budget</p><a name="_Toc123"></a><p><strong>Total</strong><o:p></o:p></p>'
      const normalized = transformPastedHTML(input)
      const inserted = editor.view.pasteHTML(input)
      const [heading, paragraph] = editor.getJSON().content
      verify('aria-heading-converted', inserted && heading?.type === 'heading' && heading.attrs.level === 2)
      verify('bold-body-kept', paragraph?.content[0].marks[0].type === 'bold')
      verify('word-artifacts-removed', !normalized.includes('_Toc123') && !normalized.includes('<o:p'))
      result.document = editor.getJSON()
      const lists = '<div class="ListContainerWrapper"><ul><li data-aria-level="1">Outer</li></ul></div><div class="ListContainerWrapper"><ul><li data-aria-level="2">Inner</li></ul></div>'
      editor.commands.clearContent()
      editor.view.pasteHTML(lists)
      const [list] = editor.getJSON().content
      verify('flat-list-nested-in-document', list?.type === 'bulletList' && list.content[0].content[1]?.type === 'bulletList')
      result.list = list
    } finally { editor.destroy() }
  })
  await run('markdown-export', async (verify, result) => {
    const editor = new Editor({ element: mount(), extensions: [StarterKit, Markdown, ExportMarkdown], content: '<h2>Budget</h2><p><strong>Total</strong></p>' })
    try {
      result.markdown = await Promise.race([
        new Promise(resolve => editor.commands.exportMarkdown({ onCompleteExport: resolve })),
        new Promise((_, reject) => setTimeout(() => reject(new Error('Markdown callback timeout')), 1000)),
      ])
      verify('heading-and-bold-serialized', /^## Budget/m.test(result.markdown) && result.markdown.includes('**Total**'))
      verify('matches-open-source-serializer', result.markdown === editor.getMarkdown())
    } finally { editor.destroy() }
  })
  async function pagination(extensions, verify, result) {
    const editor = new Editor({
      element: mount(),
      extensions: [...extensions, PageKit.configure({ pages: { pageFormat: 'A4' }, table: false })],
      content: '<p>First page</p><p>Second page</p>',
    })
    const count = () => editor.storage.pages.getPageCount?.() ?? 0
    const frame = () => new Promise(resolve => requestAnimationFrame(resolve))
    try {
      for (let index = 0; index < 30 && count() < 1; index++) await frame()
      result.before = count()
      verify('page-layout-mounted', result.before === 1)
      editor.commands.setTextSelection(12)
      verify('page-break-command-succeeds', editor.commands.insertPageBreak())
      for (let index = 0; index < 30 && count() <= result.before; index++) await frame()
      result.after = count()
      result.document = editor.getJSON()
      verify('explicit-break-stored-as-node', result.document.content.some(node => node.type === 'pageBreak'))
      verify('dom-layout-increases-page-count', result.after > result.before)
      verify('both-paragraphs-kept', editor.getText().includes('First page') && editor.getText().includes('Second page'))
    } finally { editor.destroy() }
  }
  await run('pagekit-with-starter-kit', (verify, result) => pagination([StarterKit], verify, result))
  await run('pagekit-with-convert-kit', (verify, result) => pagination([ConvertKit.configure({ table: false, pageBreak: false })], verify, result))
  async function conversion(format, extension, multipart, verify, result) {
    const command = 'export' + format[0].toUpperCase() + format.slice(1)
    const extensions = [StarterKit, extension, ...(multipart ? [ExportDocx] : [])]
    const editor = new Editor({ element: mount(), extensions, content: '<p>Own synthetic text</p>' })
    const originalFetch = window.fetch
    const endpoint = 'https://conversion.invalid/v2/convert'
    const token = 'synthetic-fixture'
    try {
      window.fetch = async (url, options) => {
        verify('mock-endpoint', String(url) === endpoint + '/export/' + format)
        const headers = new Headers(options.headers)
        verify('request-headers', options.method === 'POST' && headers.get('authorization') === 'Bearer ' + token && headers.get('x-app-id') === 'fixture')
        if (multipart) {
          const body = options.body
          const file = body instanceof FormData ? body.get('docxFile') : null
          verify('multipart-docx-file', file instanceof Blob && file.size > 4)
          const prefix = new Uint8Array(await file.slice(0, 4).arrayBuffer())
          verify('zip-signature', prefix[0] === 0x50 && prefix[1] === 0x4b)
          result.request = { path: new URL(url).pathname, body: 'multipart', fields: [...body.keys()], docxBytes: file.size }
        } else {
          const body = JSON.parse(options.body)
          verify('json-document-round-trip', JSON.parse(body.doc).content[0].content[0].text === 'Own synthetic text')
          result.request = { path: new URL(url).pathname, body: 'json', fields: Object.keys(body).sort() }
        }
        return new Response('mock file', { headers: { 'content-type': 'application/octet-stream' } })
      }
      const blob = await new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error('Conversion callback timeout')), 3500)
        const onCompleteExport = file => { clearTimeout(timer); resolve(file) }
        const onExportError = error => { clearTimeout(timer); reject(error) }
        try { Promise.resolve(editor.commands[command]({ endpoint, appId: 'fixture', token, onCompleteExport, onExportError })).catch(onExportError) }
        catch (error) { onExportError(error) }
      })
      verify('callback-got-file', blob instanceof Blob && await blob.text() === 'mock file')
    } finally {
      window.fetch = originalFetch
      editor.destroy()
    }
  }
  for (const [format, extension] of [['doc', ExportDoc], ['pdf', ExportPdf], ['odt', ExportOdt], ['epub', ExportEpub]]) {
    await run(format + '-multipart-request', (verify, result) => conversion(format, extension, true, verify, result))
  }
  await run('pdf-json-request', (verify, result) => conversion('pdf', ExportPdf, false, verify, result))
  return cases
}
`

const virtual = '\0vaulter-utilities'
const server = await createServer({
  configFile: false,
  root: lab,
  appType: 'custom',
  logLevel: 'silent',
  cacheDir: `${lab}/artifacts/utilities-vite`,
  optimizeDeps: { include: [
    '@tiptap/core', '@tiptap/starter-kit', '@tiptap/markdown',
    '@tiptap-pro/extension-export-markdown', '@tiptap-pro/extension-paste-handler',
    '@tiptap-pro/extension-pages-pagekit', '@tiptap-pro/extension-convert-kit',
    '@tiptap-pro/extension-export-docx', '@tiptap-pro/extension-export-doc',
    '@tiptap-pro/extension-export-pdf', '@tiptap-pro/extension-export-odt', '@tiptap-pro/extension-export-epub',
  ] },
  server: { host: '127.0.0.1', port: 0, hmr: false },
  plugins: [{
    name: 'vaulter-utilities-probe',
    resolveId(id) { if (id === '/probe.mjs') return virtual },
    load(id) { if (id === virtual) return fixture },
    configureServer(vite) {
      vite.middlewares.use((req, res, next) => {
        if (req.url !== '/') return next()
        res.setHeader('Content-Type', 'text/html')
        res.end('<!doctype html><meta charset="utf-8"><script type="module" src="/probe.mjs"></script>')
      })
    },
  }],
})
let browser
try {
  await server.listen()
  browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome' })
  const page = await browser.newPage()
  await page.route('**/*', route => {
    if (new URL(route.request().url()).hostname === '127.0.0.1') return route.continue()
    evidence.externalRequests++
    return route.abort()
  })
  evidence.pageErrors = []
  evidence.httpErrors = []
  page.on('pageerror', error => evidence.pageErrors.push(error.message))
  page.on('response', response => {
    if (response.status() >= 400) evidence.httpErrors.push({ path: new URL(response.url()).pathname, status: response.status() })
  })
  await page.goto(server.resolvedUrls.local[0])
  await page.waitForFunction(() => Boolean(window.utilities), undefined, { timeout: 10_000 })
  evidence.cases = await page.evaluate(() => window.utilities())
  evidence.status = evidence.cases.every(item => item.passed) && evidence.externalRequests === 0 ? 'passed' : 'partial'
} catch (error) {
  evidence.status = 'failed'
  evidence.error = error.message
} finally {
  await browser?.close()
  await server.close()
  await writeFile(output, JSON.stringify(sanitize(evidence), null, 2) + '\n')
  console.log(JSON.stringify({ status: evidence.status, cases: evidence.cases.map(({ name, passed, error }) => ({ name, passed, error })), output: fileURLToPath(output) }))
  if (evidence.status !== 'passed') process.exitCode = 1
}

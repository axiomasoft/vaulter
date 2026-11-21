import { chromium } from '../lab/node_modules/playwright/index.mjs'
import { createServer } from '../lab/node_modules/vite/dist/node/index.js'
import { mkdir, readFile, writeFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { sanitize } from './decode-frame.mjs'

const labRoot = fileURLToPath(new URL('../lab/', import.meta.url))
const resultPath = fileURLToPath(new URL('../results/tracked-changes-probe.json', import.meta.url))
const port = 5198

// The following code is our synthetic test harness. No vendor source is copied.
function installProbe() {
  const atom = Node.create({
    name: 'probeAtom', group: 'block', atom: true,
    addAttributes() { return { color: { default: 'blue' }, label: { default: 'synthetic' } } },
    renderHTML({ HTMLAttributes }) { return ['div', HTMLAttributes, 'Synthetic atom'] },
  })
  const doc = text => ({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text }] }] })
  const snapshot = editor => ({ document: editor.getJSON(), suggestions: JSON.parse(JSON.stringify(findSuggestions(editor))) })
  const create = (text = 'alpha beta', options = {}) => {
    const element = document.createElement('div')
    document.body.append(element)
    return new Editor({ element, extensions: [StarterKit, atom, TrackedChanges.configure({ enabled: true, userId: 'author-a', ...options })], content: doc(text) })
  }
  window.runTrackedChangesProbe = () => {
    const cases = []
    const checks = []
    const test = (name, callback) => {
      const entry = { name, status: 'running', observations: [] }
      cases.push(entry)
      const note = (label, editor) => entry.observations.push({ label, ...snapshot(editor) })
      const check = (name, value) => {
        checks.push({ case: entry.name, name, passed: !!value })
        if (!value) throw new Error(`Assertion failed: ${name}`)
      }
      try { callback({ note, check, entry }); entry.status = 'passed' }
      catch (error) { entry.status = 'failed'; entry.error = { name: error.name, message: error.message } }
    }

    test('mark-change-review-and-projection', ({ note, check, entry }) => {
      const editor = create('alpha beta')
      try {
        check('programmatic-mark-command-succeeds', editor.commands.addTrackedMark({ from: 1, to: 6, markName: 'bold', reason: 'synthetic-review-reason' }))
        note('bold-proposal', editor)
        let suggestion = findSuggestions(editor)[0]
        check('query-exposes-added-bold', suggestion.type === 'markChange' && suggestion.markChanges.some(change => change.operation === 'added' && change.markName === 'bold'))
        const before = JSON.stringify(editor.getJSON())
        entry.baseProjection = withoutTrackedChanges({ content: editor.getJSON(), schema: editor.schema })
        entry.acceptedProjection = resolveTrackedChanges({ content: editor.getJSON(), schema: editor.schema, accept: () => true })
        check('projection-does-not-mutate-live-editor', before === JSON.stringify(editor.getJSON()))
        check('reason-is-not-persisted', !before.includes('synthetic-review-reason'))
        check('reject-mark-succeeds', editor.commands.rejectSuggestion({ id: suggestion.id }))
        note('reject-bold', editor)
        check('reject-removes-bold', !JSON.stringify(editor.getJSON()).includes('"type":"bold"'))
        editor.commands.addTrackedMark({ from: 1, to: 6, markName: 'bold' })
        editor.commands.acceptAllSuggestions()
        note('accept-bold', editor)
        check('accept-keeps-bold-without-proposal', JSON.stringify(editor.getJSON()).includes('"type":"bold"') && findSuggestions(editor).length === 0)
      } finally { editor.destroy() }
    })

    test('atom-attribute-revise-cancel-reject-accept', ({ note, check }) => {
      const editor = create('alpha')
      try {
        editor.commands.disableTrackedChanges()
        editor.commands.insertContentAt(editor.state.doc.content.size, { type: 'probeAtom', attrs: { color: 'blue', label: 'synthetic' } })
        editor.commands.enableTrackedChanges()
        const position = 7
        const color = () => editor.state.doc.nodeAt(position).attrs.color
        const update = value => editor.chain().setNodeSelection(position).updateAttributes('probeAtom', { color: value }).run()
        update('red')
        note('attribute-red', editor)
        const first = findSuggestions(editor)[0]
        check('attribute-proposal-records-old-new', first.type === 'attributeChange' && first.attributeChanges.some(c => c.attributeName === 'color' && c.oldValue === 'blue' && c.newValue === 'red'))
        update('green')
        note('attribute-green-same-author', editor)
        const revised = findSuggestions(editor)[0]
        check('same-author-reuses-id-and-original-value', revised.id === first.id && revised.attributeChanges.some(c => c.oldValue === 'blue' && c.newValue === 'green'))
        update('blue')
        check('returning-to-original-cancels-proposal', color() === 'blue' && findSuggestions(editor).length === 0)
        update('red')
        editor.commands.rejectAllSuggestions()
        note('reject-attribute', editor)
        check('reject-restores-original-attribute', color() === 'blue' && findSuggestions(editor).length === 0)
        update('green')
        editor.commands.acceptAllSuggestions()
        note('accept-attribute', editor)
        check('accept-keeps-proposed-attribute', color() === 'green' && findSuggestions(editor).length === 0)
      } finally { editor.destroy() }
    })

    test('structural-paragraph-split', ({ note, check }) => {
      const editor = create('alpha beta')
      try {
        editor.chain().setTextSelection(6).splitBlock().run()
        note('split-existing-paragraph', editor)
        const suggestion = findSuggestions(editor).find(s => s.type === 'split')
        check('existing-text-split-is-structural', suggestion?.splitKind === 'structural' && editor.state.doc.childCount === 2)
        editor.commands.rejectAllSuggestions()
        note('reject-structural-split', editor)
        check('rejection-joins-original-paragraph', editor.state.doc.childCount === 1 && editor.getText() === 'alpha beta')
        editor.chain().setTextSelection(6).splitBlock().run()
        editor.commands.acceptAllSuggestions()
        note('accept-structural-split', editor)
        check('acceptance-keeps-two-paragraphs', editor.state.doc.childCount === 2 && findSuggestions(editor).length === 0)
      } finally { editor.destroy() }
    })

    test('end-paragraph-split', ({ note, check }) => {
      const editor = create('alpha')
      try {
        editor.chain().setTextSelection(6).splitBlock().run()
        note('split-at-end', editor)
        const suggestion = findSuggestions(editor).find(s => s.type === 'split')
        check('end-split-is-insertion-kind', suggestion?.splitKind === 'insertion')
        editor.commands.rejectAllSuggestions()
        note('reject-end-split', editor)
        check('reject-removes-added-empty-paragraph', editor.state.doc.childCount === 1 && editor.getText() === 'alpha')
      } finally { editor.destroy() }
    })

    test('adjacent-grouping-and-own-insertion-deletion', ({ note, check }) => {
      const editor = create('base')
      try {
        editor.commands.setTextSelection(1)
        editor.commands.insertContent('x')
        editor.commands.insertContent('y')
        note('two-adjacent-insertions', editor)
        check('same-author-adjacent-insertions-group', findSuggestions(editor).length === 1 && findSuggestions(editor)[0].text === 'xy')
        editor.commands.deleteRange({ from: 2, to: 3 })
        note('delete-own-pending-insertion', editor)
        check('own-pending-insert-deletion-shrinks-add', findSuggestions(editor).length === 1 && findSuggestions(editor)[0].type === 'add' && findSuggestions(editor)[0].text === 'x')
        editor.commands.setTrackedChangesUser({ userId: 'author-b' })
        editor.commands.setTextSelection(2)
        editor.commands.insertContent('z')
        note('other-author-adjacent-insertion', editor)
        check('different-author-does-not-merge-id', new Set(findSuggestions(editor).map(s => s.userId)).size === 2)
      } finally { editor.destroy() }
    })

    test('nested-authors-and-review-order', ({ note, check, entry }) => {
      const editor = create('base')
      try {
        editor.commands.addTrackedInsertion({ from: 1, content: 'Hello world ' })
        const outerId = findSuggestions(editor)[0].id
        editor.commands.setTrackedChangesUser({ userId: 'author-b' })
        editor.commands.insertContentAt(7, 'great ')
        note('b-inserts-inside-a-pending-add', editor)
        const outer = findSuggestions(editor).find(s => s.id === outerId)
        const inner = findSuggestions(editor).find(s => s.userId === 'author-b')
        check('nested-authors-keep-distinct-suggestions', !!inner && outer.id !== inner.id)
        check('outer-text-excludes-inner-insertion', outer.text === 'Hello world ' && outer.fullText === 'Hello great world ')
        editor.commands.rejectSuggestion({ id: inner.id })
        note('reject-inner-b', editor)
        check('rejecting-inner-keeps-outer-proposal', findSuggestions(editor).length === 1 && findSuggestions(editor)[0].text === 'Hello world ')
        editor.commands.acceptSuggestion({ id: outerId })
        check('accepting-outer-after-inner-rejection', findSuggestions(editor).length === 0 && editor.getText() === 'Hello world base')
        entry.final = snapshot(editor)
      } finally { editor.destroy() }
    })

    test('direct-yjs-two-editors-no-retracking', ({ note, check, entry }) => {
      const a = new Y.Doc()
      const b = new Y.Doc()
      a.on('update', (update, origin) => { if (origin !== 'peer') Y.applyUpdate(b, update, 'peer') })
      b.on('update', (update, origin) => { if (origin !== 'peer') Y.applyUpdate(a, update, 'peer') })
      const make = (document, userId) => {
        const element = window.document.createElement('div')
        window.document.body.append(element)
        return new Editor({ element, extensions: [StarterKit.configure({ undoRedo: false }), Collaboration.configure({ document }), TrackedChanges.configure({ enabled: true, userId })] })
      }
      const editorA = make(a, 'author-a')
      const editorB = make(b, 'author-b')
      try {
        editorA.commands.disableTrackedChanges()
        editorA.commands.setContent(doc('base'))
        editorA.commands.enableTrackedChanges()
        editorA.commands.addTrackedInsertion({ from: 1, content: 'Hello world ' })
        note('a-after-local-insertion', editorA)
        note('b-after-remote-insertion', editorB)
        check('remote-update-is-not-retracked-as-b', findSuggestions(editorB).length === 1 && findSuggestions(editorB)[0].userId === 'author-a')
        editorB.commands.insertContentAt(7, 'great ')
        note('a-after-b-nested-insert', editorA)
        note('b-after-own-nested-insert', editorB)
        check('both-live-documents-converge', JSON.stringify(editorA.getJSON()) === JSON.stringify(editorB.getJSON()))
        check('two-authors-not-duplicate-remote-proposals', findSuggestions(editorA).length === 2 && new Set(findSuggestions(editorA).map(s => s.userId)).size === 2)
        entry.transport = 'direct-Y.applyUpdate-in-memory; no provider, auth or Cloud server'
      } finally { editorA.destroy(); editorB.destroy(); a.destroy(); b.destroy() }
    })

    test('node-type-proposals-yjs-guard', ({ check, entry }) => {
      const ydoc = new Y.Doc()
      let editor
      let caught
      try {
        editor = new Editor({ element: document.createElement('div'), extensions: [StarterKit.configure({ undoRedo: false }), Collaboration.configure({ document: ydoc }), TrackedChanges.configure({ nodeTypeChangeTypes: ['paragraph', 'heading'] })] })
      } catch (error) { caught = error.message }
      finally { editor?.destroy(); ydoc.destroy() }
      entry.initializationError = caught ?? null
      check('unsupported-type-change-yjs-combination-rejected', typeof caught === 'string' && /collab|Yjs|Y\.js/i.test(caught))
    })

    return { status: cases.every(c => c.status === 'passed') ? 'passed' : 'failed', cases, checks }
  }
}

const browserCode = `
import { Editor, Node } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Collaboration from '@tiptap/extension-collaboration';
import * as Y from 'yjs';
import { TrackedChanges, findSuggestions, withoutTrackedChanges, resolveTrackedChanges } from '@tiptap-pro/extension-tracked-changes';
(${installProbe.toString()})();
`
let server
let browser
let result = { runAt: new Date().toISOString(), scope: 'installed-Pro-0.14.3-local-black-box-no-Cloud', status: 'blocked' }
try {
  server = await createServer({
    configFile: false, root: labRoot,
    cacheDir: `${labRoot}/node_modules/.vite-tracked-changes-probe`,
    optimizeDeps: { noDiscovery: true, include: ['@tiptap/core', '@tiptap/starter-kit', '@tiptap/extension-collaboration', '@tiptap-pro/extension-tracked-changes', 'yjs'] },
    server: { host: '127.0.0.1', port, strictPort: true },
    plugins: [{
      name: 'synthetic-tracked-changes-probe',
      resolveId(id) { if (id === '/__tracked-changes-probe.mjs') return '\0tracked-changes-probe' },
      load(id) { if (id === '\0tracked-changes-probe') return browserCode },
      configureServer(server) {
        server.middlewares.use(async (request, response, next) => {
          if (request.url?.split('?')[0] !== '/__tracked-changes-probe') return next()
          response.setHeader('Content-Type', 'text/html')
          response.end(await server.transformIndexHtml('/__tracked-changes-probe', '<!doctype html><html><body><script type="module" src="/__tracked-changes-probe.mjs"></script></body></html>'))
        })
      },
    }],
  })
  await server.listen()
  browser = await chromium.launch({ channel: 'chrome', headless: true })
  const page = await browser.newPage()
  const moduleErrors = []
  result.moduleErrors = moduleErrors
  page.on('pageerror', error => moduleErrors.push(sanitize({ name: error.name, message: error.message })))
  await page.goto(`http://127.0.0.1:${port}/__tracked-changes-probe`, { waitUntil: 'networkidle', timeout: 30000 })
  await page.waitForFunction(() => typeof window.runTrackedChangesProbe === 'function', null, { timeout: 20000 })
  result = { ...result, ...await page.evaluate(() => window.runTrackedChangesProbe()), browser: browser.version(), moduleErrors }
  result.package = JSON.parse(await readFile(new URL('../lab/node_modules/@tiptap-pro/extension-tracked-changes/package.json', import.meta.url), 'utf8')).version
} catch (error) {
  result.error = sanitize({ name: error.name, message: error.message })
} finally {
  await browser?.close()
  await server?.close()
  await mkdir(fileURLToPath(new URL('../results/', import.meta.url)), { recursive: true })
  await writeFile(resultPath, JSON.stringify(sanitize(result), null, 2) + '\n')
  console.log(JSON.stringify({ status: result.status, cases: result.cases?.map(({ name, status, error }) => ({ name, status, error })), checks: result.checks?.length, output: resultPath, error: result.error }, null, 2))
  if (result.status !== 'passed') process.exitCode = 1
}

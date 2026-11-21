import { test, expect } from '@playwright/test'

async function connect(page, room) {
  await page.goto('/')
  await page.waitForFunction(() => Boolean(window.lab))
  await page.evaluate(input => window.lab.connect(input), { mode: 'local', room })
  await expect.poll(() => page.evaluate(() => window.lab.states.length === 2 && window.lab.states.every(
    state => state.authenticated && state.synced && state.status === 'connected',
  ))).toBe(true)
}

test('local OSS: legacy schema reports unknown heading without deleting it from the shared document', async ({ browser }, testInfo) => {
  const room = `tiptap-pro-lab-schema-${crypto.randomUUID()}`
  const heading = 'Heading unavailable in legacy schema'
  const paragraph = 'A normal paragraph remains editable by current clients.'
  const expected = {
    type: 'doc', content: [
      { type: 'heading', attrs: { level: 2, blockId: 'schema-heading' }, content: [{ type: 'text', text: heading }] },
      { type: 'paragraph', attrs: { blockId: 'schema-paragraph' }, content: [{ type: 'text', text: paragraph }] },
    ],
  }
  const writerContext = await browser.newContext()
  const legacyContext = await browser.newContext()
  const freshContext = await browser.newContext()
  const evidence = { mode: 'local', server: 'OSS Hocuspocus lab; not Tiptap Cloud', room, expected, stages: {} }
  let legacyPage
  try {
    const writerPage = await writerContext.newPage()
    await connect(writerPage, room)
    await writerPage.evaluate(content => window.lab.editors[0].commands.setContent(content), expected)
    await expect.poll(() => writerPage.evaluate(() => window.lab.editors.map(editor => editor.getJSON()))).toEqual([expected, expected])
    await expect.poll(() => writerPage.evaluate(() => window.lab.states.every(state => state.unsynced === 0))).toBe(true)
    evidence.stages.beforeLegacy = await writerPage.evaluate(() => window.lab.snapshot().json)

    legacyPage = await legacyContext.newPage()
    await legacyPage.goto('/')
    await legacyPage.waitForFunction(() => Boolean(window.lab))
    // Connect the ordinary clients to a separate room solely to obtain the actual
    // installed constructors/extensions. The probe below uses its own Y.Doc/provider.
    await connect(legacyPage, `${room}-constructors`)
    await legacyPage.evaluate(async ({ room }) => {
      const config = await fetch('/api/config').then(response => response.json())
      const currentEditor = window.lab.editors[0]
      const Editor = currentEditor.constructor
      const Doc = window.lab.docs[0].constructor
      const Provider = window.lab.providers[0].constructor
      const doc = new Doc()
      const probe = { doc, errors: [], authenticated: false, synced: false, disabled: false }
      window.schemaProbe = probe
      const provider = new Provider({
        url: config.localUrl,
        name: room,
        document: doc,
        token: async () => {
          const response = await fetch('/api/token', {
            method: 'POST', headers: { 'content-type': 'application/json' },
            body: JSON.stringify({ mode: 'local', room, user: 'legacy-schema-writer' }),
          })
          if (!response.ok) throw new Error('Cannot issue schema probe token')
          return (await response.json()).token
        },
        onAuthenticated() { probe.authenticated = true },
        onSynced({ state }) { probe.synced = state },
      })
      probe.provider = provider
      const extensions = currentEditor.options.extensions.map(extension => {
        if (extension.name === 'starterKit') return extension.configure({ heading: false })
        if (extension.name === 'collaboration') return extension.configure({ document: doc })
        if (extension.name === 'collaborationCaret') return extension.configure({ provider, user: { name: 'Legacy', color: '#777777' } })
        return extension.configure({})
      })
      const element = document.createElement('div')
      element.dataset.testid = 'legacy-schema-editor'
      document.body.append(element)
      probe.editor = new Editor({
        element,
        enableContentCheck: true,
        extensions,
        onContentError({ error, disableCollaboration }) {
          probe.errors.push({ name: error?.name ?? null, message: error?.message ?? null })
          disableCollaboration()
          probe.disabled = true
        },
      })
    }, { room })
    await expect.poll(() => legacyPage.evaluate(() => window.schemaProbe.authenticated)).toBe(true)
    expect(await legacyPage.evaluate(() => Boolean(window.schemaProbe.editor.schema.nodes.heading))).toBe(false)
    await expect.poll(() => legacyPage.evaluate(() => window.schemaProbe.errors.length)).toBeGreaterThan(0)
    expect(await legacyPage.evaluate(() => window.schemaProbe.disabled)).toBe(true)
    evidence.stages.legacy = await legacyPage.evaluate(() => ({
      authenticated: window.schemaProbe.authenticated,
      synced: window.schemaProbe.synced,
      headingInSchema: Boolean(window.schemaProbe.editor.schema.nodes.heading),
      errors: window.schemaProbe.errors,
      disabled: window.schemaProbe.disabled,
      editable: window.schemaProbe.editor.isEditable,
      visibleJson: window.schemaProbe.editor.getJSON(),
    }))

    // A separate normal browser context reads the server state after the legacy
    // client has encountered the unsupported node. The test never repairs content.
    const freshPage = await freshContext.newPage()
    await connect(freshPage, room)
    await expect.poll(() => freshPage.evaluate(() => window.lab.snapshot().json)).toEqual([expected, expected])
    expect(await writerPage.evaluate(() => window.lab.snapshot().json)).toEqual([expected, expected])
    evidence.stages.fresh = await freshPage.evaluate(() => window.lab.snapshot().json)
    evidence.outcome = 'passed: contentError observed and independent normal clients retain the original heading'
  } catch (error) {
    evidence.outcome = 'failed: see Playwright assertion and captured stages; no production/vendor modification made'
    throw error
  } finally {
    if (legacyPage && !legacyPage.isClosed()) {
      await legacyPage.evaluate(() => {
        window.schemaProbe?.provider.destroy()
        window.schemaProbe?.editor.destroy()
        window.schemaProbe?.doc.destroy()
      }).catch(() => {})
    }
    await testInfo.attach('schema-compatibility-observation', { body: Buffer.from(JSON.stringify(evidence, null, 2)), contentType: 'application/json' })
    await writerContext.close()
    await legacyContext.close()
    await freshContext.close()
  }
})

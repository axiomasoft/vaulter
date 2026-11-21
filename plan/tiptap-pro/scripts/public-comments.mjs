#!/usr/bin/env node
import { chromium } from '../lab/node_modules/playwright-core/index.mjs'
import { expect } from '../lab/node_modules/@playwright/test/index.mjs'
import { randomUUID } from 'node:crypto'
import { mkdir, writeFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { decodeFrame, sanitize } from './decode-frame.mjs'

const source = 'https://embed-pro.tiptap.dev/preview/Extensions/Comments?hideSource=false&inline=false'
const outputDirectory = fileURLToPath(new URL('../results/', import.meta.url))
const runId = randomUUID()
const ownText = `Vaulter public demo text ${runId}`
const ownComment = `Vaulter synthetic comment ${runId}`
const ownReply = `Vaulter synthetic reply ${runId}`
const evidence = {
  schema: 'tiptap-pro-public-comments/v1', runId, startedAt: new Date().toISOString(),
  target: 'PUBLIC OFFICIAL DEMO; not the configured user Cloud environment', source,
  browser: { channel: 'chrome', headless: true, isolatedContext: true },
  operations: [], requests: [], websockets: [], checks: {},
  collection: { responseHeaders: false, responseBodies: false, requestHeaders: false, requestBodies: false, sourceCode: false, demoCredentials: false },
}
let browser
let stage = 'launch'
let frame
let editor
let selectedOwnThread = false

function endpoint(url) {
  try {
    const parsed = new URL(url)
    return sanitize(`${parsed.protocol}//${parsed.host}${parsed.pathname}`)
  } catch {
    return '[unparseable endpoint]'
  }
}

async function inspectOwnContent(label) {
  return editor.evaluate((element, { marker, label }) => {
    const instance = element.editor
    if (!instance || typeof instance.getJSON !== 'function') return { label, standardEditorExposed: false }
    const matches = []
    const visit = node => {
      if (node.type === 'text' && node.text?.includes(marker)) matches.push(node)
      for (const child of node.content || []) visit(child)
    }
    visit(instance.getJSON())
    return { label, standardEditorExposed: true, matchingTextNodes: matches }
  }, { marker: ownText, label })
}

async function main() {
  try {
    browser = await chromium.launch({ channel: 'chrome', headless: true })
    const context = await browser.newContext()
    const page = await context.newPage()
    page.setDefaultTimeout(10_000)
    page.on('response', response => {
      if (evidence.requests.length >= 250) return
      evidence.requests.push({ method: response.request().method(), endpoint: endpoint(response.url()), status: response.status(), resourceType: response.request().resourceType() })
    })
    page.on('requestfailed', request => {
      if (evidence.requests.length >= 250) return
      evidence.requests.push({ method: request.method(), endpoint: endpoint(request.url()), status: null, resourceType: request.resourceType(), failure: 'request_failed' })
    })
    page.on('websocket', socket => {
      const record = { endpoint: endpoint(socket.url()), frames: [], droppedFrames: 0 }
      evidence.websockets.push(record)
      const capture = direction => event => {
        if (record.frames.length >= 300) { record.droppedFrames++; return }
        record.frames.push({ direction, ...decodeFrame(event.payload) })
      }
      socket.on('framesent', capture('sent'))
      socket.on('framereceived', capture('received'))
      socket.on('socketerror', () => { record.connectionError = true })
      socket.on('close', () => { record.closed = true })
    })
    page.on('dialog', async dialog => {
      // The public Add comment button opens this DOM-visible browser prompt.
      if (dialog.type() === 'prompt' && dialog.message() === 'Comment content') {
        evidence.operations.push({ action: 'accept-comment-prompt', prompt: 'Comment content', syntheticContent: true })
        await dialog.accept(ownComment)
      } else {
        evidence.operations.push({ action: 'dismiss-unexpected-dialog', type: dialog.type() })
        await dialog.dismiss()
      }
    })

    stage = 'navigate'
    const navigation = await page.goto(source, { waitUntil: 'domcontentloaded', timeout: 30_000 })
    evidence.navigationStatus = navigation?.status() ?? null
    stage = 'wait-for-demo-editor'
    // Read the interactive iframe only; the wrapper's source-code panel is deliberately ignored.
    const demo = page.frameLocator('iframe').first()
    editor = demo.locator('[contenteditable="true"]').first()
    await editor.waitFor({ state: 'visible', timeout: 20_000 })
    frame = page.frames().find(candidate => candidate !== page.mainFrame() && /\/src\/extensions\/comments\//i.test(candidate.url()))
    if (!frame) throw new Error('interactive_frame_not_found')
    evidence.frameEndpoint = endpoint(frame.url())
    evidence.initialUi = {
      buttons: await frame.getByRole('button').evaluateAll(nodes => nodes.map(node => ({ name: node.innerText, disabled: node.disabled }))),
      editors: await frame.locator('[contenteditable="true"]').count(),
      filters: await frame.locator('input[type="radio"]').evaluateAll(nodes => nodes.map(node => ({ label: node.parentElement.innerText.trim(), checked: node.checked }))),
    }
    evidence.checks.editorLoaded = true
    evidence.operations.push({ action: 'locate-interactive-editor', wrapperSourcePanelRead: false })

    stage = 'insert-and-select-synthetic-paragraph'
    const insertion = await editor.evaluate((element, marker) => {
      const instance = element.editor
      if (!instance || typeof instance.commands?.insertContentAt !== 'function') return { supported: false }
      instance.commands.insertContentAt(instance.state.doc.content.size, { type: 'paragraph', content: [{ type: 'text', text: marker }] })
      let selection
      instance.state.doc.descendants((node, position) => {
        if (node.isText && node.text === marker) selection = { from: position, to: position + marker.length }
      })
      if (!selection) return { supported: true, selected: false }
      instance.commands.setTextSelection(selection)
      return { supported: true, selected: true, selection }
    }, ownText)
    evidence.operations.push({ action: 'insert-synthetic-paragraph-and-select', through: 'standard DOM element.editor public commands', ...insertion })
    if (!insertion.selected) throw new Error('standard_editor_selection_unavailable')
    evidence.beforeThread = await inspectOwnContent('before-thread')
    await frame.getByRole('button', { name: 'Add comment', exact: true }).click()
    await frame.getByText(ownComment, { exact: true }).waitFor({ state: 'visible' })
    evidence.checks.commentVisible = true
    evidence.afterThread = await inspectOwnContent('after-thread')

    stage = 'select-own-thread-and-reply'
    await frame.getByText(ownComment, { exact: true }).click()
    selectedOwnThread = true
    const reply = frame.getByPlaceholder('Reply to thread …')
    await reply.waitFor({ state: 'visible' })
    await reply.fill(ownReply)
    const submit = frame.getByRole('button', { name: /^(Send|Reply|Post|Submit)( comment| reply)?$/i })
    evidence.replyUi = { buttons: await frame.getByRole('button').allTextContents(), placeholder: await reply.getAttribute('placeholder') }
    if (await submit.count() === 1) {
      await submit.click()
      evidence.operations.push({ action: 'submit-own-reply', through: 'visible-submit-button' })
    } else {
      await reply.press('Enter')
      evidence.operations.push({ action: 'submit-own-reply', through: 'textarea-Enter' })
    }
    await frame.getByText(ownReply, { exact: true }).waitFor({ state: 'visible', timeout: 5000 })
    evidence.checks.replyVisible = true

    stage = 'resolve-own-thread'
    const resolve = frame.getByRole('button', { name: /^✓ Resolve$/ })
    await resolve.click()
    await frame.getByText(ownComment, { exact: true }).waitFor({ state: 'hidden' })
    evidence.checks.hiddenFromOpenAfterResolve = true
    evidence.afterResolve = await inspectOwnContent('after-resolve')
    stage = 'verify-resolved-filter'
    evidence.beforeResolvedFilter = await frame.locator('input[type="radio"]').evaluateAll(nodes => nodes.map(node => ({ label: node.parentElement.innerText.trim(), checked: node.checked })))
    await frame.getByText('Resolved', { exact: true }).click()
    evidence.afterResolvedFilter = await frame.locator('input[type="radio"]').evaluateAll(nodes => nodes.map(node => ({ label: node.parentElement.innerText.trim(), checked: node.checked })))
    await frame.getByText(ownComment, { exact: true }).waitFor({ state: 'visible' })
    evidence.checks.visibleInResolved = true
    evidence.operations.push({ action: 'resolve-own-thread-and-open-resolved-filter' })
    stage = 'wait-for-server-apply-observation'
    try {
      await expect.poll(() => evidence.websockets.flatMap(socket => socket.frames).filter(item => item.direction === 'received' && item.typeName === 'SyncStatus' && item.applied === true).length, { timeout: 10_000 }).toBeGreaterThan(0)
      evidence.wireConfirmation = 'server-apply-status-observed'
    } catch {
      evidence.wireConfirmation = 'not-observed-within-timeout'
    }
    evidence.status = 'passed'
  } catch (error) {
    // Exceptions can include page snippets or URLs. Store only their class and the known stage.
    evidence.status = evidence.checks.commentVisible ? 'partial' : 'blocked'
    evidence.blocker = { stage, errorClass: error?.name || 'Error', details: 'See stage and recorded HTTP/WS metadata; raw exception deliberately omitted.' }
    if (frame && editor && selectedOwnThread) {
      evidence.lastUi = {
        buttons: await frame.getByRole('button').allTextContents().catch(() => []),
        filters: await frame.locator('input[type="radio"]').evaluateAll(nodes => nodes.map(node => ({ label: node.parentElement.innerText.trim(), checked: node.checked }))).catch(() => []),
        ownCommentMatches: await frame.getByText(ownComment, { exact: true }).count().catch(() => 0),
        dataViewModes: await frame.locator('[data-viewmode]').evaluateAll(nodes => nodes.map(node => node.getAttribute('data-viewmode'))).catch(() => []),
        replyFields: await frame.locator('textarea').evaluateAll(nodes => nodes.map(node => ({ placeholder: node.getAttribute('placeholder'), valueIsSyntheticReply: node.value.startsWith('Vaulter synthetic reply ') }))).catch(() => []),
      }
    }
  } finally {
    if (browser) await browser.close()
    evidence.finishedAt = new Date().toISOString()
    evidence.frameSummary = evidence.websockets.flatMap(socket => socket.frames).reduce((counts, frame) => {
      const name = `${frame.direction}:${frame.typeName || frame.status}${frame.syncName ? `:${frame.syncName}` : ''}`
      counts[name] = (counts[name] || 0) + 1
      return counts
    }, {})
    const safe = sanitize(evidence)
    await mkdir(outputDirectory, { recursive: true })
    await writeFile(`${outputDirectory}/public-comments.json`, `${JSON.stringify(safe, null, 2)}\n`)
    const allFrames = evidence.websockets.flatMap(socket => socket.frames)
    const appliedAcknowledgements = allFrames.filter(item => item.direction === 'received' && item.typeName === 'SyncStatus' && item.applied === true).length
    const serverEvents = allFrames.filter(item => item.direction === 'received' && item.typeName === 'Stateless').map(item => item.payload?.event).filter(Boolean)
    const threadMarks = evidence.afterThread?.matchingTextNodes?.flatMap(node => node.marks || []) || []
    const resolvedMarks = evidence.afterResolve?.matchingTextNodes?.flatMap(node => node.marks || []) || []
    const lines = [
      '# Публичный Comments demo: наблюдения', '',
      `Дата: ${evidence.startedAt}. Результат: **${evidence.status}**.`, '',
      `Источник: [официальный публичный demo](${source}). Это **не** собственное Cloud-окружение пользователя; его ключи и npm-доступ здесь не проверяются.`, '',
      `Изолированный headless Chrome; HTTP navigation: ${evidence.navigationStatus ?? 'не получен'}. Интерактивный editor загружен: ${Boolean(evidence.checks.editorLoaded)}.`, '',
      '| Наблюдение | Подтверждено |', '| --- | --- |',
      ...Object.entries(evidence.checks).map(([name, result]) => `| ${name} | ${result} |`), '',
      'Добавление текста и выделение выполняются через стандартный `element.editor` Tiptap и публичные commands; обсуждение создаётся видимой кнопкой Add comment и браузерным prompt Comment content. Проверяется только собственный synthetic текст. Source panel и bundle не используются.', '',
      `HTTP metadata записей: ${evidence.requests.length}; WebSocket соединений: ${evidence.websockets.length}. Headers, HTTP body и demo credentials не собирались. Кадры обработаны decode-frame.mjs: auth payload скрыт, binary payload сохранён только размером и SHA256. Полные результаты: [public-comments.json](results/public-comments.json).`, '',
      ...(evidence.blocker ? [`Ограничение: сценарий остановился на этапе \`${evidence.blocker.stage}\` (${evidence.blocker.errorClass}); оставшиеся действия не считаются успешными. Повторных попыток в скрипте нет.`, ''] : []),
      '## Наблюдаемый контракт', '',
      `До создания thread own text не имел marks. После: \`${JSON.stringify(threadMarks)}\`. После resolve: \`${JSON.stringify(resolvedMarks)}\`. Это снимки public editor.getJSON(), а не извлечение внутренних переменных приложения.`, '',
      evidence.checks.replyVisible ? 'Собственное обсуждение открывается кликом по тексту comment. Reply composer — textarea с placeholder Reply to thread …; после ввода появляется Send. Отправленная synthetic reply стала видимой в том же thread.' : 'Полный сценарий reply не подтверждён.', '',
      evidence.checks.hiddenFromOpenAfterResolve ? 'Кнопка ✓ Resolve скрыла собственное обсуждение из Open. Inline anchor сохранился в JSON editor. Проверка появления в Resolved считается успешной только при visibleInResolved=true в evidence.' : 'Resolve не подтверждён.', '',
      `За сессию получено SyncStatus(applied=true): ${appliedAcknowledgements}. Stateless events: \`${JSON.stringify(serverEvents)}\`. Служебный запрос versions.list и ответ списка видны в redacted кадрах, если они присутствуют в JSON evidence.`, '',
      '## Следствия для собственной реализации', '',
      'Observed anchor связывает текст с thread идентификатором; состояние resolved не равно удалению anchor. Собственной системе нужны раздельные жизненные циклы rich-text anchor, discussion record и UI filter. Конкретные storage types thread не выводятся из JSON text editor; для них нужен отдельный опыт Y.Doc/API.', '',
      'Локальное появление comment/reply или изменение фильтра не доказывает server durability. Наличие отправленного Sync Update не доказывает принятие сервером; входящий SyncStatus даёт дополнительный сигнал applied, но без crash/reconnect опыта не доказывает дисковую фиксацию. document.saved — наблюдаемый event без установленной здесь связи с ACID commit. Внутреннюю SQL-схему и транзакционный порядок этот опыт не определяет.', '',
    ]
    await writeFile(fileURLToPath(new URL('../public-comments-findings.md', import.meta.url)), lines.join('\n'))
    console.log(JSON.stringify({ status: evidence.status, checks: evidence.checks, blocker: evidence.blocker, requests: evidence.requests.length, frameSummary: evidence.frameSummary }, null, 2))
    if (evidence.status !== 'passed') process.exitCode = 1
  }
}

await main()

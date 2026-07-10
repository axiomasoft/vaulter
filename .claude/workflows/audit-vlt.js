export const meta = {
  name: 'audit-vlt',
  description: 'Исполняет мастер-план AUDIT-VLT: read-only аудит пакета vaulter по чеклистам, с адверсариальной верификацией находок',
  whenToUse: 'Когда нужно прогнать фазы P0/P1 плана plans/AUDIT-VLT параллельно вместо последовательных /task:plan-exec. Аудит read-only: продакшн-код не меняется.',
  phases: [
    { title: 'P0 · Baseline', detail: 'P0.1 — инвентаризация репозитория и тулинга' },
    { title: 'P0 · Research', detail: 'P0.2 RAG-добивка (opus + perplexity/context7) ∥ P0.3 карта поверхности атаки', model: 'opus' },
    { title: 'P0 · Review', detail: 'Фазовое ревью качества исполнения: вердикт GREEN/ATTENTION/RED', model: 'opus' },
    { title: 'P0 · Repair', detail: 'Один раунд починки items, забракованных ревью' },
    { title: 'P0 · Close', detail: 'REGISTER, статусы фазы, handoff, коммит (только при не-RED)' },
    { title: 'P1 · Независимые', detail: 'P1.1–P1.8: авторизация, драйверы, шэринг, загрузки, CAS, collab, webhook, секреты' },
    { title: 'P1 · Зависимые', detail: 'P1.9 → P1.10 (нужен P1.1), P1.11, P1.12 (нужны P1.3/P1.5)' },
    { title: 'P1 · Review', detail: 'Фазовое ревью качества исполнения: вердикт GREEN/ATTENTION/RED', model: 'opus' },
    { title: 'P1 · Repair', detail: 'Один раунд починки items, забракованных ревью' },
    { title: 'Verify', detail: 'Адверсариальная верификация каждой находки Blocker/Major тремя скептиками' },
    { title: 'P1 · Close', detail: 'REGISTER, статусы фазы, Phase Handoff, handoff.md, коммит (только при не-RED)' },
  ],
}

// ─── Общие константы ────────────────────────────────────────────────────────

const PLAN = 'plans/AUDIT-VLT'
const PHP = '/usr/bin/php8.4'

const FINDING_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  required: ['item', 'findingsFile', 'checksTotal', 'checksVerdicted', 'findings', 'limitations'],
  properties: {
    item: { type: 'string', description: 'ID item\'а, например P1.4' },
    findingsFile: { type: 'string', description: 'Путь к созданному файлу находок' },
    checksTotal: { type: 'integer', description: 'Сколько строк C1..Cn в чеклисте item\'а' },
    checksVerdicted: { type: 'integer', description: 'Сколько строк получили вердикт (pass/fail/partial/n-a)' },
    treeClean: { type: 'boolean', description: 'git status по packages/docs/e2e/hocuspocus-server пуст (D2)' },
    findings: {
      type: 'array',
      items: {
        type: 'object',
        additionalProperties: false,
        required: ['id', 'severity', 'confidence', 'title', 'file', 'norm'],
        properties: {
          id: { type: 'string', description: 'F-<item>-NN' },
          severity: { type: 'string', enum: ['Blocker', 'Major', 'Minor', 'Nit'] },
          confidence: { type: 'string', enum: ['high', 'medium', 'low'] },
          title: { type: 'string' },
          file: { type: 'string', description: 'file:line' },
          norm: { type: 'string', description: 'Нарушенная норма: U2.4 / C2.1 / G2.2 / S3.1 / ADR-000X' },
          summary: { type: 'string' },
        },
      },
    },
    limitations: { type: 'string', description: 'Что осталось непроверенным и почему' },
  },
}

const VERDICT_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  required: ['refuted', 'reason'],
  properties: {
    refuted: { type: 'boolean', description: 'true = находка опровергнута (ложное срабатывание)' },
    reason: { type: 'string', description: 'Чем именно опровергнута или почему подтверждена; с file:line' },
    severityAdjustment: { type: 'string', enum: ['keep', 'raise', 'lower'] },
  },
}

const CLOSE_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  required: ['commit', 'registerRows', 'lintOk'],
  properties: {
    commit: { type: 'string', description: 'sha коммита или "none"' },
    registerRows: { type: 'integer' },
    lintOk: { type: 'boolean' },
    notes: { type: 'string' },
  },
}

const REVIEW_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  required: ['verdict', 'rejectedItems', 'defects'],
  properties: {
    verdict: {
      type: 'string',
      enum: ['GREEN', 'ATTENTION', 'RED'],
      description: 'GREEN — фаза исполнена честно; ATTENTION — есть слабые места, но коммит допустим; RED — исполнение недостоверно, коммит запрещён',
    },
    rejectedItems: {
      type: 'array',
      description: 'ID items, чьё исполнение требует переделки',
      items: { type: 'string' },
    },
    defects: {
      type: 'array',
      items: {
        type: 'object',
        additionalProperties: false,
        required: ['item', 'axis', 'evidence'],
        properties: {
          item: { type: 'string' },
          axis: {
            type: 'string',
            enum: ['unverdicted-check', 'no-evidence', 'weasel-wording', 'repro-not-run', 'read-only-violation', 'scope-drift', 'norm-not-cited', 'adr-not-checked'],
          },
          evidence: { type: 'string', description: 'Цитата из файла находок + путь' },
        },
      },
    },
    summary: { type: 'string' },
  },
}

// ─── Промпты ────────────────────────────────────────────────────────────────

function itemPrompt(id, phaseFile) {
  return `Ты исполняешь РОВНО ОДИН item мастер-плана AUDIT-VLT в репозитории vaulter
(рабочая директория — корень репозитория).

Item: ${id}
Файл фазы: ${PLAN}/${phaseFile}

## Обязательный порядок

1. Прочитай ${PLAN}/plan.md — секции «2. Execution Rules» и «5. Decision Log» (решения D1–D18).
2. Прочитай ${PLAN}/artifacts/finding-template.md — схема файла находок и шкала severity.
3. Прочитай в ${PLAN}/${phaseFile} раздел «### ${id} — …» ЦЕЛИКОМ.
4. Прочитай его Required Reads в указанном порядке. Файла нет → НЕ выдумывай содержимое:
   зафиксируй расхождение плана с кодом в поле limitations и продолжай с остальными.
5. Выполни Implementation Rules буквально: закрытый чеклист C1..Cn.
   Каждая строка обязана получить вердикт: ✅ pass (с file:line), ❌ fail (с находкой),
   ⚠ partial, n/a (с причиной). Формулировки «выглядит корректно», «проблем не нашёл»,
   «вероятно, ок» ЗАПРЕЩЕНЫ — это дефект исполнения.
6. Где Implementation Rules требует repro — напиши временный Pest-тест в
   ${PLAN}/artifacts/repro/${id}/ и прогони: ${PHP} vendor/bin/pest ${PLAN}/artifacts/repro/${id}
   Фактический вывод процитируй в находке.
7. Напиши файл находок ${PLAN}/findings/${id}-<slug>.md строго по схеме шаблона.

## Жёсткие запреты

- Аудит READ-ONLY (D2). НЕ меняй ни одного файла вне ${PLAN}/.
  Проверь в конце: git status --porcelain -- packages docs e2e hocuspocus-server
  Вывод обязан быть пустым; результат положи в поле treeClean.
- Repro-тесты кладутся ТОЛЬКО в ${PLAN}/artifacts/repro/${id}/ (D6). В packages/*/tests — запрещено.
- НЕ трогай ${PLAN}/findings/REGISTER.md, НЕ меняй статусы в plan.md и в файлах фаз,
  НЕ делай git commit. Это сделает отдельная стадия закрытия фазы (D19).
- RAG-выжимки ${PLAN}/findings/P0-*.md НОРМАТИВНЫ и неизменны (D16): сверяй код с ними,
  не правь их под код.
- До заведения находки сверься с соответствующим docs/adr/*.md (D5): расхождение с
  сознательным решением ADR — находка класса «ADR устарел», а не «баг».
- Нашёл Blocker → НЕ чини (D2). Зафиксируй находку и продолжай чеклист.

PHP-бинарь: ${PHP} (системный php — 8.5, проект требует ^8.4).

Верни структурированный результат: путь к файлу находок, число строк чеклиста, число
вердиктов, список находок, ограничения.`
}

function reviewPrompt(phase, itemIds, results) {
  const stats = results
    .filter(Boolean)
    .map(r => `- ${r.item}: файл ${r.findingsFile}, вердиктов ${r.checksVerdicted}/${r.checksTotal}, находок ${r.findings.length}, tree clean ${r.treeClean}`)
    .join('\n')

  return `Ты — независимый ревьюер фазы ${phase} мастер-плана AUDIT-VLT. Ты НЕ проводишь аудит кода
заново. Ты проверяешь, что аудит был проведён ЧЕСТНО, и ищешь признаки его имитации.

Отчёты исполнителей:
${stats}

## Что прочитать

1. ${PLAN}/plan.md — «2. Execution Rules» и решения D2, D3, D4, D5, D6, D16.
2. ${PLAN}/artifacts/finding-template.md — обязательная схема файла находок.
3. ${PLAN}/phases/${phase}.md — чеклисты C1..Cn каждого item'а (это эталон).
4. КАЖДЫЙ файл ${PLAN}/findings/<item>-*.md для items: ${itemIds.join(', ')}.

## Оси ревью — ищи дефекты именно этих классов

- unverdicted-check: строка чеклиста из phases/${phase}.md отсутствует в файле находок ИЛИ
  не имеет вердикта. Сверь ПОШТУЧНО: число строк C в эталоне против числа строк в отчёте.
- no-evidence: вердикт \`✅ pass\` без \`file:line\`, либо находка без доказательства
  (цитаты кода или вывода repro).
- weasel-wording: «выглядит корректно», «вероятно», «проблем не обнаружено», «скорее всего»,
  «похоже, защищено» — прямой запрет D3. Грепни файлы находок по этим формулировкам.
- repro-not-run: Implementation Rules требует repro для конкретных строк C, но в
  ${PLAN}/artifacts/repro/<item>/ пусто либо вывод прогона не приведён.
- read-only-violation: git status --porcelain -- packages docs e2e hocuspocus-server непуст,
  либо в packages/*/tests появились repro-файлы (D6):
  find packages -path '*/tests/*' -name 'repro*'
- scope-drift: item ушёл в чужую ось (например, аудит производительности внутри P1).
- norm-not-cited: находка не ссылается на нарушенную норму (U/C/S/E/G-пункт или ADR).
- adr-not-checked: находка по теме, у которой есть docs/adr/*.md, без строки «Сверка с ADR».

## Вердикт

- RED — если есть хотя бы один read-only-violation ИЛИ если у любого item'а доля
  вердиктов < 100% ИЛИ найден weasel-wording в вердиктах \`pass\`.
  RED означает: коммит фазы запрещён, items переделываются.
- ATTENTION — дефекты есть, но они не отравляют выводы (например, no-evidence на одной
  строке Minor-находки).
- GREEN — только когда каждая строка каждого чеклиста имеет вердикт, каждый \`pass\` несёт
  file:line, каждый требуемый repro прогнан, дерево чистое.

Ноль находок в фазе НЕ является основанием для RED сам по себе — но обязан быть подкреплён
доказательствами по каждой строке. Аудит без находок и без доказательств = имитация → RED.

Ты работаешь read-only: ничего не правишь и не коммитишь. Верни вердикт, список items на
переделку и перечень дефектов с цитатами.`
}

function repairPrompt(id, phaseFile, defects) {
  const list = defects.map(d => `- [${d.axis}] ${d.evidence}`).join('\n')

  return `Ревью фазы забраковало твой item ${id}. Переделай ровно то, на что указано.

Дефекты:
${list}

## Что сделать

1. Перечитай раздел «### ${id} — …» в ${PLAN}/${phaseFile} — чеклист C1..Cn это эталон.
2. Перечитай свой файл ${PLAN}/findings/${id}-*.md.
3. Закрой КАЖДЫЙ дефект:
   - unverdicted-check → добавь недостающие строки чеклиста с вердиктами;
   - no-evidence → открой код, приведи file:line и цитату;
   - weasel-wording → замени на ✅ pass с доказательством, ❌ fail с находкой,
     ⚠ partial или n/a с причиной. Ослаблять чеклист, чтобы получить pass, ЗАПРЕЩЕНО;
   - repro-not-run → напиши тест в ${PLAN}/artifacts/repro/${id}/ и прогони:
     ${PHP} vendor/bin/pest ${PLAN}/artifacts/repro/${id} — вывод процитируй;
   - read-only-violation → откати изменения вне ${PLAN}/ (git checkout -- <файлы>)
     и перенеси repro в ${PLAN}/artifacts/repro/${id}/;
   - norm-not-cited / adr-not-checked → допиши ссылку на норму (U/C/S/E/G-пункт)
     и строку «Сверка с ADR».

ЗАПРЕЩЕНО подгонять вердикт под удобство: если проверка честно даёт ❌ fail — так и пиши,
даже если это Blocker. Аудит READ-ONLY (D2): продакшн-код не чини.
Не трогай REGISTER.md, статусы и git.

Верни обновлённый структурированный результат по своему item'у.`
}

function closePrompt(phase, itemIds, results) {
  const summary = results
    .filter(Boolean)
    .map(r => `- ${r.item}: ${r.findings.length} находок (${r.checksVerdicted}/${r.checksTotal} вердиктов), tree clean: ${r.treeClean}`)
    .join('\n')

  return `Ты закрываешь фазу ${phase} мастер-плана AUDIT-VLT. Кода не пишешь, аудит не ведёшь —
только сводишь артефакты и коммитишь.

Итоги исполнителей:
${summary}

## Что сделать

1. Прочитай КАЖДЫЙ файл ${PLAN}/findings/<item>-*.md для items: ${itemIds.join(', ')}.
2. Допиши все находки строками в ${PLAN}/findings/REGISTER.md (append-only, существующие
   строки не править; удали строку-заглушку «(пусто: аудит не начат)», если она ещё там).
   Колонки: ID | Item | Severity | Уверенность | Заголовок | Где (file:line) | Норма.
3. Проставь статусы: в ${PLAN}/phases/${phase}.md таблица «Phase Status» и в
   ${PLAN}/plan.md «4. Phase Index & Status Board». Статус item'а — 🟢 Done, если чеклист
   закрыт полностью; 🟠 Done with deviations, если остались ⚠ partial; 🔴 Blocked, если
   исполнитель эскалировал. Статусы — 6 канонических строк байт-в-байт.
   Столбец «Items 🟢/всего» в Status Board обнови фактическим числом.
4. Заполни «## Phase Handoff» в ${PLAN}/phases/${phase}.md: что сдали, находки по severity,
   известные отклонения.
5. Перезапиши ${PLAN}/handoff.md целиком по шаблону: заголовок
   «# HANDOFF — <ISO-дата> — after <последний закрытый item>», Next в форме launch-block
   (таблица с | Model | + code-block с голой командой), Done / Remaining / Sources of truth /
   Open risks / Workarounds-Deferred-Open questions. Все Blocker'ы обязаны попасть в Open risks.
   Дату возьми из вывода команды: date +%F
6. Допиши строку в «## 6. Update Log» plan.md (≤2 строк, без пересказа Completion Notes).
7. Прогони линтер:
   python3 "/home/vostrikov/projects/packages/swissknifeman/packages/task/scripts/plan-lint.py" ${PLAN}
   Красное — почини.
8. Коммит-гейт: убедись, что git status --porcelain -- packages docs e2e hocuspocus-server пуст.
   Затем: git add plans/AUDIT-VLT && git commit
   Сообщение — Conventional Commits, тип docs(plan), тело на русском, одна строка сути на item.
   Продакшн-код в коммите отсутствовать обязан.

Верни: sha коммита, число дописанных строк REGISTER, зелёный ли линтер.`
}

function verifyPrompt(finding, lens) {
  return `Ты СКЕПТИК. Твоя задача — ОПРОВЕРГНУТЬ находку аудита, а не подтвердить её.
Работай через линзу: ${lens}.

Находка ${finding.id} (severity ${finding.severity}, уверенность ${finding.confidence}):
«${finding.title}»
Где: ${finding.file}
Нарушенная норма: ${finding.norm}
Суть: ${finding.summary || '—'}

## Как опровергать

1. Открой указанный file:line и прочитай окружающий код целиком, а не одну строку.
2. Найди причину, по которой находка НЕВЕРНА: защита выше по стеку (middleware, policy,
   FormRequest, гейт фиче-флага), недостижимый код, неверно понятая семантика библиотеки,
   сознательное решение в docs/adr/*.md, тестовый/стаб-код вместо продакшн-пути.
3. Если находка утверждает уязвимость — попробуй воспроизвести обратное: покажи, что путь
   закрыт. Repro пиши в plans/AUDIT-VLT/artifacts/repro/verify-${finding.id}/ и гоняй через
   ${PHP} vendor/bin/pest <путь>.
4. Норму читай в plans/AUDIT-VLT/findings/P0-*.md — она нормативна (D16).

Правь только файлы под plans/AUDIT-VLT/. Продакшн-код не трогай.

При сомнении ставь refuted=true: ложная находка дороже пропущенной на этом шаге —
пропущенную поймает следующий раунд, а ложная отравит бэклог.

Верни вердикт: опровергнута ли находка, чем именно (с file:line), и надо ли двигать severity.`
}

// ─── Определения фаз ────────────────────────────────────────────────────────

const P1_INDEPENDENT = [
  { id: 'P1.1', model: 'opus', effort: 'high' },
  { id: 'P1.2' },
  { id: 'P1.3' },
  { id: 'P1.4' },
  { id: 'P1.5' },
  { id: 'P1.6', model: 'opus', effort: 'high' },
  { id: 'P1.7' },
  { id: 'P1.8' },
]

const VERIFY_LENSES = ['корректность: верно ли прочитан код и семантика', 'достижимость: есть ли реальный путь эксплуатации от недоверенного актора', 'намеренность: не является ли это сознательным решением ADR или задокументированным ограничением']

// ─── Сборка ─────────────────────────────────────────────────────────────────

const requested = (args && args.phases) || ['P0', 'P1']
const doVerify = !args || args.verify !== false
const runP0 = requested.includes('P0')
const runP1 = requested.includes('P1')

const allResults = []

/**
 * Фазовый гейт: независимое ревью качества исполнения → один раунд починки →
 * повторное ревью. Возвращает { verdict, results } с обновлёнными отчётами.
 */
async function phaseGate(phaseId, phaseFile, itemIds, results) {
  phase(`${phaseId} · Review`)

  const review = await agent(reviewPrompt(phaseId, itemIds, results), {
    label: `${phaseId} review: честность исполнения`,
    phase: `${phaseId} · Review`,
    schema: REVIEW_SCHEMA,
    model: 'opus',
    effort: 'xhigh',
  })

  if (!review) {
    log(`⚠ ${phaseId}: ревьюер не вернул вердикт — считаем ATTENTION, коммит разрешён`)
    return { verdict: 'ATTENTION', results }
  }

  log(`${phaseId} review: ${review.verdict}, дефектов ${review.defects.length}, на переделку ${review.rejectedItems.length}`)

  if (review.verdict === 'GREEN' || review.rejectedItems.length === 0) {
    return { verdict: review.verdict, results }
  }

  phase(`${phaseId} · Repair`)

  const repaired = await parallel(
    review.rejectedItems.map(id => () => {
      const defects = review.defects.filter(d => d.item === id)
      return agent(repairPrompt(id, phaseFile, defects), {
        label: `${id} repair (${defects.length} дефектов)`,
        phase: `${phaseId} · Repair`,
        schema: FINDING_SCHEMA,
        effort: 'high',
      })
    })
  )

  const merged = results.filter(Boolean).map(r => {
    const fixed = repaired.filter(Boolean).find(x => x.item === r.item)
    return fixed || r
  })

  const recheck = await agent(reviewPrompt(phaseId, itemIds, merged), {
    label: `${phaseId} re-review после починки`,
    phase: `${phaseId} · Review`,
    schema: REVIEW_SCHEMA,
    model: 'opus',
    effort: 'xhigh',
  })

  const verdict = recheck ? recheck.verdict : 'ATTENTION'
  log(`${phaseId} re-review: ${verdict}`)

  if (verdict === 'RED') {
    log(`🔴 ESCALATION-REQUIRED: ${phaseId} — исполнение недостоверно после раунда починки. Коммит фазы не будет сделан.`)
  }

  return { verdict, results: merged }
}

if (runP0) {
  phase('P0 · Baseline')
  const p01 = await agent(itemPrompt('P0.1', 'P0.md'), {
    label: 'P0.1 baseline-инвентаризация',
    phase: 'P0 · Baseline',
    schema: FINDING_SCHEMA,
    effort: 'medium',
  })

  log('P0.1 закрыт — инвентаризация есть, P0.3 может строить карту поверхности')

  phase('P0 · Research')
  const p0rest = await parallel([
    () => agent(itemPrompt('P0.2', 'P0.md'), {
      label: 'P0.2 RAG-добивка (T1–T6)',
      phase: 'P0 · Research',
      schema: FINDING_SCHEMA,
      model: 'opus',
      effort: 'high',
    }),
    () => agent(itemPrompt('P0.3', 'P0.md'), {
      label: 'P0.3 карта поверхности атаки',
      phase: 'P0 · Research',
      schema: FINDING_SCHEMA,
      effort: 'medium',
    }),
  ])

  const p0raw = [p01, ...p0rest].filter(Boolean)
  const gate0 = await phaseGate('P0', 'P0.md', ['P0.1', 'P0.2', 'P0.3'], p0raw)
  allResults.push(...gate0.results)

  if (gate0.verdict === 'RED') {
    return {
      halted: 'P0',
      reason: 'Фазовое ревью вернуло RED после раунда починки — исполнение недостоверно, коммита нет',
      itemsClosed: [],
      escalation: 'ESCALATION-REQUIRED: P0 — переделать вручную через /task:plan-exec, затем /task:plan-audit AUDIT-VLT P0',
    }
  }

  phase('P0 · Close')
  const close0 = await agent(closePrompt('P0', ['P0.1', 'P0.2', 'P0.3'], gate0.results), {
    label: 'P0 close: REGISTER + статусы + коммит',
    phase: 'P0 · Close',
    schema: CLOSE_SCHEMA,
    effort: 'low',
  })
  log(`P0 закрыт (${gate0.verdict}): commit ${close0 ? close0.commit : 'n/a'}, lint ${close0 && close0.lintOk ? 'зелёный' : 'ПРОВАЛ'}`)

  if (close0 && !close0.lintOk) {
    log('⚠ plan-lint красный после P0 — P1 всё равно запускается, но handoff сомнителен')
  }
}

let p1all = []

if (runP1) {
  phase('P1 · Независимые')
  const p1indep = await parallel(
    P1_INDEPENDENT.map(it => () =>
      agent(itemPrompt(it.id, 'P1.md'), {
        label: `${it.id} audit`,
        phase: 'P1 · Независимые',
        schema: FINDING_SCHEMA,
        model: it.model,
        effort: it.effort || 'medium',
      })
    )
  )

  const done = p1indep.filter(Boolean)
  log(`P1.1–P1.8: ${done.length}/8 закрыто, находок ${done.reduce((n, r) => n + r.findings.length, 0)}`)

  // P1.9 читает findings P1.1; P1.10 читает P1.1 + P1.9 → строгая цепочка.
  // P1.11 независим. P1.12 читает P1.3 + P1.5 (уже готовы).
  phase('P1 · Зависимые')
  const chained = await parallel([
    () =>
      agent(itemPrompt('P1.9', 'P1.md'), {
        label: 'P1.9 tenant-изоляция',
        phase: 'P1 · Зависимые',
        schema: FINDING_SCHEMA,
        effort: 'medium',
      }).then(r9 =>
        agent(itemPrompt('P1.10', 'P1.md'), {
          label: 'P1.10 Filament-поверхность',
          phase: 'P1 · Зависимые',
          schema: FINDING_SCHEMA,
          effort: 'medium',
        }).then(r10 => [r9, r10])
      ),
    () =>
      agent(itemPrompt('P1.11', 'P1.md'), {
        label: 'P1.11 контентная безопасность',
        phase: 'P1 · Зависимые',
        schema: FINDING_SCHEMA,
        effort: 'medium',
      }).then(r => [r]),
    () =>
      agent(itemPrompt('P1.12', 'P1.md'), {
        label: 'P1.12 remanence',
        phase: 'P1 · Зависимые',
        schema: FINDING_SCHEMA,
        effort: 'medium',
      }).then(r => [r]),
  ])

  const p1raw = [...done, ...chained.filter(Boolean).flat().filter(Boolean)]

  const P1_IDS = ['P1.1', 'P1.2', 'P1.3', 'P1.4', 'P1.5', 'P1.6', 'P1.7', 'P1.8', 'P1.9', 'P1.10', 'P1.11', 'P1.12']
  const gate1 = await phaseGate('P1', 'P1.md', P1_IDS, p1raw)

  if (gate1.verdict === 'RED') {
    return {
      halted: 'P1',
      reason: 'Фазовое ревью вернуло RED после раунда починки — находки недостоверны, верификация и коммит не запускались',
      itemsClosed: allResults.map(r => r.item),
      escalation: 'ESCALATION-REQUIRED: P1 — переделать забракованные items вручную, затем /task:plan-audit AUDIT-VLT P1',
    }
  }

  p1all = gate1.results
  allResults.push(...p1all)
  log(`P1 прошла фазовый гейт: ${gate1.verdict}`)
}

// ─── Адверсариальная верификация ────────────────────────────────────────────

let verified = []

if (doVerify) {
  const serious = allResults.flatMap(r => r.findings.filter(f => f.severity === 'Blocker' || f.severity === 'Major'))

  if (serious.length === 0) {
    log('Находок Blocker/Major нет — верификация пропущена. ВНИМАНИЕ: ноль серьёзных находок сам по себе не вердикт (P5.1 sanity-гейт).')
  } else {
    log(`Верификация: ${serious.length} находок Blocker/Major × 3 скептика`)
    phase('Verify')

    verified = await parallel(
      serious.map(f => () =>
        parallel(
          VERIFY_LENSES.map((lens, i) => () =>
            agent(verifyPrompt(f, lens), {
              label: `verify ${f.id} · линза ${i + 1}`,
              phase: 'Verify',
              schema: VERDICT_SCHEMA,
              effort: 'high',
            })
          )
        ).then(votes => {
          const v = votes.filter(Boolean)
          const refutedCount = v.filter(x => x.refuted).length
          return {
            finding: f,
            survives: refutedCount < 2,
            refutedCount,
            votes: v.map(x => x.reason),
          }
        })
      )
    )

    const killed = verified.filter(Boolean).filter(v => !v.survives)
    log(`Верификация завершена: подтверждено ${verified.filter(Boolean).length - killed.length}, опровергнуто ${killed.length}`)
  }
}

// ─── Закрытие P1 ────────────────────────────────────────────────────────────

let close1 = null

if (runP1) {
  phase('P1 · Close')

  const refuted = verified
    .filter(Boolean)
    .filter(v => !v.survives)
    .map(v => `- ${v.finding.id} «${v.finding.title}» — опровергнута ${v.refutedCount}/3 скептиками`)
    .join('\n')

  const verifyNote = refuted
    ? `\n\n## Результаты адверсариальной верификации\n\nСледующие находки ОПРОВЕРГНУТЫ большинством скептиков — в REGISTER их НЕ вносить, но в файле находок пометить строкой «опровергнуто верификацией, см. artifacts/repro/verify-<id>/»:\n${refuted}`
    : '\n\nВсе находки Blocker/Major пережили адверсариальную верификацию.'

  close1 = await agent(
    closePrompt('P1', ['P1.1', 'P1.2', 'P1.3', 'P1.4', 'P1.5', 'P1.6', 'P1.7', 'P1.8', 'P1.9', 'P1.10', 'P1.11', 'P1.12'], p1all) +
      verifyNote +
      `\n\nЕсли среди подтверждённых находок есть хотя бы один Blocker — статус плана в plan.md
остаётся 🟡 In progress, а Blocker'ы перечисляются в handoff.md → Open risks (D7).
Next в launch-block — /task:plan-design AUDIT-VLT P2 (развёртывание скелета фазы, D17).`,
    {
      label: 'P1 close: REGISTER + статусы + handoff + коммит',
      phase: 'P1 · Close',
      schema: CLOSE_SCHEMA,
      effort: 'low',
    }
  )
}

// ─── Итог ───────────────────────────────────────────────────────────────────

const bySeverity = { Blocker: 0, Major: 0, Minor: 0, Nit: 0 }
for (const r of allResults) for (const f of r.findings) bySeverity[f.severity]++

const refutedIds = verified
  .filter(Boolean)
  .filter(v => !v.survives)
  .map(v => v.finding.id)

return {
  itemsClosed: allResults.map(r => r.item),
  checks: allResults.reduce((n, r) => n + r.checksTotal, 0),
  verdicts: allResults.reduce((n, r) => n + r.checksVerdicted, 0),
  findingsBySeverity: bySeverity,
  confirmedBlockers: allResults
    .flatMap(r => r.findings)
    .filter(f => f.severity === 'Blocker' && !refutedIds.includes(f.id))
    .map(f => `${f.id} — ${f.title} (${f.file})`),
  refutedByVerification: refutedIds,
  treeDirty: allResults.filter(r => r.treeClean === false).map(r => r.item),
  commits: [close1 ? close1.commit : null].filter(Boolean),
  limitations: allResults.map(r => `${r.item}: ${r.limitations}`),
}

export const meta = {
  name: 'vlt-remed-tail',
  description: 'Доводит VLT-REMED до конца: P4.1 (hocuspocus на актуальный мажор + починка websocket-hardening теста) → P4.9 (канон структуры + ADR + arch-тесты) → P4.10 (массовый namespace-refactor без изменения поведения) → пересборка closure-матрицы и вердикта release-readiness. Базовый vaulter-dev-loop НЕ трогает.',
  whenToUse: 'Финальное закрытие хвостов VLT-REMED (P4.1/P4.9/P4.10) автономно, до зелёного вердикта.',
  phases: [
    { title: 'P4.1', detail: 'hocuspocus → актуальный мажор + переписать websocket-hardening тест (opus/high)' },
    { title: 'P4.9', detail: 'канон структуры: ADR + arch-тесты, red на текущей раскладке (opus/high)' },
    { title: 'P4.10', detail: 'namespace-refactor к канону без изменения поведения (opus/xhigh)' },
    { title: 'Matrix', detail: 'пересборка closure-матрицы 103 находок + release-readiness' },
  ],
}

const EXEC_SCHEMA = {
  type: 'object',
  required: ['item', 'status', 'escalation', 'summary'],
  properties: {
    item: { type: 'string' },
    status: { enum: ['done', 'done_with_deviations', 'blocked'] },
    escalation: { type: 'boolean' },
    summary: { type: 'string' },
    commits: { type: 'array', items: { type: 'string' } },
  },
}

const REVIEW_SCHEMA = {
  type: 'object',
  required: ['blockers', 'majors', 'findings'],
  properties: {
    blockers: { type: 'integer' },
    majors: { type: 'integer' },
    findings: {
      type: 'array',
      items: {
        type: 'object',
        required: ['severity', 'file', 'summary'],
        properties: {
          severity: { enum: ['Blocker', 'Major', 'Minor', 'Nit'] },
          file: { type: 'string' },
          line: { type: 'integer' },
          summary: { type: 'string' },
        },
      },
    },
  },
}

const MATRIX_SCHEMA = {
  type: 'object',
  required: ['rowsFound', 'expected', 'missing', 'verdictHonest', 'verdict'],
  properties: {
    rowsFound: { type: 'integer' },
    expected: { type: 'integer' },
    missing: { type: 'array', items: { type: 'string' } },
    verdictHonest: { type: 'boolean' },
    verdict: { type: 'string' },
    notes: { type: 'string' },
  },
}

const COLD_START = `
Репозиторий /home/vostrikov/projects/packages/vaulter, ветка remediation/audit-vlt (D8).
SSOT: plans/VLT-REMED/plan.md, handoff.md, phases/P4.md. Реестр (append-only, НЕ править):
plans/AUDIT-VLT/findings/REGISTER.md (103). Финальное ревью: plans/VLT-REMED/findings/P5.1-review-findings.md.
Closure-матрица: plans/VLT-REMED/findings/P5.3-closure-matrix.md.

СОСТОЯНИЕ: закрыты P0–P3, P4.2–P4.8, вся P5 (26 items + 11 находок ревью R-01…R-11, верифицированы
исполнением). Осталось довести три хвоста — их и делаем.

⚑ АКТУАЛЬНОСТЬ ВЕРСИЙ (жёсткое требование владельца): любые версии зависимостей — САМЫЕ АКТУАЛЬНЫЕ
стабильные на МОМЕНТ ИСПОЛНЕНИЯ. НЕ доверяй памяти/training data: проверь текущий стабильный релиз
через RAG (context7 → perplexity-web → WebSearch) ПЕРЕД тем как пинить. Скиллы verify-claims +
query-craft — обязательны для любого version-claim.

Жёсткие правила:
- Гейт из корня: composer lint:check && composer analyse && composer test && composer test:types.
  Затронуты documents/drive/media/filament/core — гони и их собственные
  \`cd packages/<pkg> && composer analyse && composer test && composer test:types\`.
  Для hocuspocus-server: \`cd hocuspocus-server && npm test && npm run check\`; для vue — сборка/тесты пакета.
  Красный lint:check ТОЛЬКО на plans/AUDIT-VLT/artifacts/repro/** — пред-существующий долг вне scope.
- На каждый F-id — регрессионный тест, КРАСНЫЙ на коде ДО фикса, с F-id в описании (D7), ГОНЯЕТСЯ в CI.
- Тип коммита ЛАТИНИЦЕЙ (feat/fix/docs/refactor/test/ci/chore), subject — РЕАЛЬНОЕ описание, не шаблон.
- План разошёлся с кодом → НЕ импровизировать, эскалация.
`.trim()

// ── P4.1: hocuspocus на актуальный мажор + починка теста ────────────────────
const p41Prompt = `
${COLD_START}

ЗАДАЧА: закрыть ЕДИНСТВЕННУЮ открытую находку item'а P4.1 — F-P4.5-02 — и перевести P4.1 из 🔴 в 🟢.
Остальные 13/14 находок P4.1 уже закрыты; НЕ переделывай их.

F-P4.5-02: @hocuspocus/* запинен на ^2.15 (hocuspocus-server/package.json: server + extension-logger/
redis/throttle) и @hocuspocus/provider ^2.13 (packages/vue). Ночью попытка бампа роняла регресс-тест
hocuspocus-server/…/websocket-hardening.test.js в бесконечный hang — тест опирался на v2.x-специфичное
внутреннее API (реальный сдвиг контракта), бамп был реверчен, находка помечена ⛔ Skipped by decision.

Владелец требует довести до конца на АКТУАЛЬНОЙ версии. Сделай:
1. RAG-проверь текущий стабильный мажор @hocuspocus/server, /provider и extensions (logger/redis/throttle)
   — context7/perplexity, не из памяти. Зафиксируй найденную версию и дату проверки в Completion Notes.
2. Подними ВСЕ @hocuspocus/* согласованно (server, extensions, provider в vue) до этого мажора;
   при необходимости — сопутствующие peer-deps (yjs и т.п.) до совместимых актуальных.
3. Перепиши websocket-hardening.test.js под НОВОЕ публичное API (не под внутреннее v2.x). Тест обязан
   проверять ТОТ ЖЕ инвариант безопасности (throttle/лимиты соединения/защита от флуда), что и раньше,
   быть зелёным на новом API и КРАСНЫМ, если защиту снять. НЕ markTestSkipped, НЕ ослабляй ассерты.
4. Прогони hocuspocus-server npm test + check; убедись, что backend-швы (JWT-валидация, webhook,
   снапшоты) не сломаны бампом. PHP-гейт из корня — зелёный.
5. Закрой P4.1 по plan-протоколу: коммит(ы), статус 🟢 (или 🟠 с явным обоснованием, если найдёшь
   реальный остаточный риск), синхронизация Phase Status/Status Board/Update Log, перезапись handoff.md,
   plan-lint.py. Обнови F-P4.5-02 в closure-матрице с 🔴/отложен на закрыт.

escalation=true — только если актуальный мажор несёт СВОЙ разрыв контракта, который нельзя закрыть
без нового решения владельца (тогда подробно опиши что именно). Верни результат.
`.trim()

// ── P4.9 / P4.10: exec через plan-протокол ──────────────────────────────────
const execItem = (item, extra) => `
${COLD_START}

ЗАДАЧА: исполни item ${item} мастер-плана VLT-REMED по полному plan-протоколу (цикл 1–8),
включая закрытие: коммит(ы) Conventional Commits (тип латиницей), статус item'а, Completion Notes/
Pending Work/Known Deviations, синхронизация Phase Status/Status Board/Update Log, перезапись
handoff.md, plan-lint.py.

Сделай это, вызвав скилл: Skill(skill="task:plan-exec", args="VLT-REMED ${item}").
${extra}
escalation=true — только при реальном упоре в решение человека. Верни результат.
`.trim()

const p49Extra = `
Специфика P4.9 (канон структуры — «объявление закона», кода приложения НЕ переносим):
- Инвентаризуй раскладку ВСЕХ шести PHP-пакетов; зафиксируй канон A (layer-first + доменный
  под-namespace) в ADR под docs/adr/.
- Напиши Pest Arch-тесты, кодирующие канон. Они ОБЯЗАНЫ быть КРАСНЫМИ на текущей раскладке
  documents/drive (доказательство, что канон ещё не соблюдён) — это и есть red-before для P4.10.
  Пометь их todo/skip с явной привязкой «зеленеют в P4.10», либо оставь красными в отдельном
  arch-suite, который P4.10 обязан позеленить. НЕ подгоняй канон под текущую раскладку.
- Активируй скилл php:namespace-refactor-safety для дисциплины (даже на этапе объявления).
`.trim()

const p410Extra = `
Специфика P4.10 (исполнение канона P4.9 — массовый namespace-refactor). КРИТИЧНО:
- ПОВЕДЕНИЕ НЕ МЕНЯЕТСЯ. Доказательство неизменности: весь сьют зелёный БЕЗ правок тестов сверх
  \`use\`-строк/ссылок на namespace; arch-тесты канона P4.9 (были red/todo) становятся green.
- Дисциплина обязательна (скилл php:namespace-refactor-safety): три фазы alias → обновление use →
  hard rename; find-replace якорить на FQN, НЕ на голый токен (иначе коллизии префиксов); на швах
  оставить контракт для phpstan; arch-тесты для паритета слоёв.
- Это BREAKING по публичной поверхности (перемещение классов/namespace) → обязательная запись в
  UPGRADE.md с картой старый→новый FQCN и bump-указанием (major).
- Порядок пакетов от менее к более связанным; после КАЖДОГО пакета — зелёный гейт, отдельный коммит.
- НЕ смешивай перенос с логическими правками: если по пути видишь дефект — фиксируй в Pending Work,
  не чини в этом же коммите (иначе «неизменность поведения» недоказуема).
`.trim()

// ── Ревью-промпты ───────────────────────────────────────────────────────────
const reviewPrompt = (item, axes) => `
Read-only adversarial-ревью item'а ${item} плана VLT-REMED (ветка remediation/audit-vlt).
Дифф: \`git log --oneline -15\`, коммиты с "(${item})", смотри \`git show\`. Кода НЕ меняй.

${axes}

Общие оси: «зелёное, но не починенное» (тест красный на коде ДО фикса? гоняется в CI, не осиротел?);
спец-код под тест (отклоняется КЛАСС, не строка); публичный контракт/SemVer + UPGRADE.md;
ServiceProvider register-vs-boot; scope drift. Гоняй vendor/bin/phpstan analyse + целевые тесты.
Верни только находки.
`.trim()

const P41_AXES = `Специфика P4.1:
1. @hocuspocus/* реально подняты до АКТУАЛЬНОГО стабильного мажора (сверь package.json + lock;
   версия подтверждена RAG, а не выдумана) — согласованно server/extensions/provider.
2. websocket-hardening.test.js переписан под ПУБЛИЧНОЕ API нового мажора, НЕ skipped, зелёный,
   и КРАСНЕЕТ при снятии защиты (проверь revert). Тот же инвариант безопасности, что до бампа.
3. Бамп не сломал backend-швы: JWT-валидация, webhook, снапшоты.`

const P49_AXES = `Специфика P4.9 (объявление канона, БЕЗ переноса кода):
1. ADR в docs/adr/ фиксирует канон A однозначно (не расплывчато).
2. Arch-тесты КРАСНЫЕ на текущей раскладке documents/drive (это red-before для P4.10) — проверь,
   что они правда падают сейчас, а не зелёные заглушки. Канон НЕ подогнан под текущую раскладку.
3. Кода приложения не переносили (это P4.10) — только ADR + тесты.`

const P410_AXES = `Специфика P4.10 (массовый namespace-refactor — ГЛАВНАЯ ось: ПОВЕДЕНЧЕСКАЯ НЕЙТРАЛЬНОСТЬ):
1. Тесты изменены ТОЛЬКО в use-строках/namespace-ссылках — НЕ в ассертах/логике. Пройди git show
   по тест-файлам построчно; любая правка ассерта = красный флаг «подгонка под рефактор».
2. Arch-тесты канона P4.9 (были red) теперь GREEN — канон реально исполнен, а не обойдён.
3. Никаких логических правок под видом переноса: диффы вне move (изменение тела метода) — scope drift.
4. UPGRADE.md несёт карту старый→новый FQCN, помечено major-breaking.
5. phpstan на швах чист; нет осиротевших старых namespace (голых токенов после find-replace).`

const autoFix = (item, findings) => `
${COLD_START}

Ревью item'а ${item} нашло дефекты. Почини ОДНОЗНАЧНОЕ строго в рамках Scope Included ${item}:

${findings.map((f, i) => `${i + 1}. [${f.severity}] ${f.file}${f.line ? ':' + f.line : ''} — ${f.summary}`).join('\n')}

Чини механическое/однозначное. Для P4.10: если находка = «поведение изменилось» — откати логическую
правку, оставь ТОЛЬКО перенос. Если нужен НОВЫЙ архитектурный/SemVer-выбор вне уже принятого канона —
НЕ импровизируй: escalation=true. Фиксы — ОТДЕЛЬНЫМ коммитом \`fix(scope): реальное описание (${item})\`
(тип латиницей). Гейт зелёный ДО коммита.
`.trim()

const matrixPrompt = `
${COLD_START}

ЗАДАЧА: ПЕРЕСОБЕРИ plans/VLT-REMED/findings/P5.3-closure-matrix.md — три хвоста (P4.1/P4.9/P4.10)
только что закрыты, матрица и вердикт устарели.

- Строка на КАЖДУЮ из 103 находок REGISTER.md: \`F-id | батч | item | коммит (sha) | тест | статус\`.
- Обнови статусы F-P4.5-02 (P4.1) и структурных находок P4.9/P4.10 на закрыт со ссылкой на ЖИВОЙ
  коммит и ЖИВОЙ тест. НЕ рисуй «закрыт» без живого коммита/теста — честный «отложен»/«принят».
- ПЕРЕСЧИТАЙ вердикт release-readiness по критериям плана: 3 Blocker'а закрыты, UPGRADE.md покрывает
  все breaking (включая namespace-карту P4.10), гейты зелёные. Если всё сошлось — вердикт может стать
  ГОТОВ; если остались открытые — честный НЕ ГОТОВ со списком.
- Закрой по plan-протоколу: Phase Status/Status Board/Update Log/handoff.md, plan-lint.py.
Коммит: \`docs(plan): пересборка closure-матрицы и вердикта после закрытия P4.1/P4.9/P4.10 (P5.3)\`.
`.trim()

const matrixVerify = `
${COLD_START}

МАШИННЫЙ ВЕРИФИКАТОР closure-матрицы (plans/VLT-REMED/findings/P5.3-closure-matrix.md). Read-only.
1. rowsFound (строк матрицы) vs expected (F-id в REGISTER.md) — посчитай оба грепом. Ждём 103.
2. Пропущенные F-id → missing[].
3. Каждая «закрыт» ссылается на живой коммит (\`git cat-file -e\`) и живой тест (grep F-id) —
   иначе "fake-closed: <F-id>" в missing[].
4. verdict — процитируй итоговый вердикт матрицы. verdictHonest=false, если матрица рисует «готов»
   при живых открытых items (проверь: P4.1/P4.9/P4.10 действительно закрыты коммитами? 3 Blocker'а?).
`.trim()

// ─── Прогон ─────────────────────────────────────────────────────────────────
const ROUNDS = 3
const closed = []
const queued = []
const queue = (item, reason, findings) => {
  queued.push({ item, reason, findings: findings ?? [] })
  log(`🟠 ${item}: человеку — ${reason}`)
}

// Универсальный шаг: exec → review-гейт (converge-or-queue). Возвращает статус закрытия.
async function runItem({ id, ph, execFn, reviewAxes, reviewModel, reviewEffort }) {
  phase(ph)
  log(`${id}: exec`)
  const exec = await execFn()
  if (!exec) {
    queue(id, 'исполнитель умер', [])
    return 'dead'
  }
  if (exec.status === 'blocked') {
    queue(id, `blocked: ${exec.summary}`, [])
    return 'blocked'
  }
  if (exec.escalation) {
    queue(id, `эскалация: ${exec.summary}`, [])
    return 'escalated'
  }

  let clean = false
  let lastHard = []
  for (let round = 1; round <= ROUNDS; round++) {
    const review = await agent(reviewPrompt(id, reviewAxes), {
      label: `review:${id}${round > 1 ? `#${round}` : ''}`,
      phase: ph,
      agentType: 'vaulter-reviewer',
      model: reviewModel,
      effort: reviewEffort,
      schema: REVIEW_SCHEMA,
    })
    if (!review) break
    const hard = review.findings.filter((f) => f.severity === 'Blocker' || f.severity === 'Major')
    lastHard = hard
    if (hard.length === 0) {
      clean = true
      log(`✓ ${id}: ревью чистое (Minor/Nit: ${review.findings.length})`)
      break
    }
    if (round === ROUNDS) break
    log(`${id}: ${hard.length} Blocker/Major — авто-фикс раунд ${round}`)
    const fixed = await agent(autoFix(id, hard), {
      label: `fix:${id}#${round}`,
      phase: ph,
      model: 'opus',
      effort: reviewEffort,
      schema: EXEC_SCHEMA,
    })
    if (!fixed || fixed.escalation) {
      if (fixed?.escalation) log(`${id}: авто-фикс эскалировал — человеку`)
      break
    }
  }

  if (clean) {
    closed.push(id)
    return 'closed'
  }
  queue(id, `ревью не сошлось после авто-фиксов (осталось ${lastHard.length} Blocker/Major)`, lastHard)
  return 'unconverged'
}

// P4.1 — независимый (hocuspocus/JS-сторона). Deep review opus/high.
const r41 = await runItem({
  id: 'P4.1',
  ph: 'P4.1',
  execFn: () => agent(p41Prompt, { label: 'exec:P4.1', phase: 'P4.1', model: 'opus', effort: 'high', schema: EXEC_SCHEMA }),
  reviewAxes: P41_AXES,
  reviewModel: 'opus',
  reviewEffort: 'high',
})

// P4.9 — канон (объявление). Deep review opus/high. Гейтит P4.10.
const r49 = await runItem({
  id: 'P4.9',
  ph: 'P4.9',
  execFn: () => agent(execItem('P4.9', p49Extra), { label: 'exec:P4.9', phase: 'P4.9', model: 'opus', effort: 'high', schema: EXEC_SCHEMA }),
  reviewAxes: P49_AXES,
  reviewModel: 'opus',
  reviewEffort: 'high',
})

// P4.10 — исполнение канона. Запускаем ТОЛЬКО если канон P4.9 реально объявлен (exec не умер/не blocked).
// Даже при неполном ревью P4.9 (остались Minor) канон закоммичен — refactor может идти.
let r410 = 'skipped'
if (r49 === 'closed' || r49 === 'unconverged') {
  r410 = await runItem({
    id: 'P4.10',
    ph: 'P4.10',
    execFn: () => agent(execItem('P4.10', p410Extra), { label: 'exec:P4.10', phase: 'P4.10', model: 'opus', effort: 'xhigh', schema: EXEC_SCHEMA }),
    reviewAxes: P410_AXES,
    reviewModel: 'opus',
    reviewEffort: 'xhigh',
  })
} else {
  queue('P4.10', `не запущен: канон P4.9 не объявлен (${r49}) — рефакторинг без канона недопустим`, [])
}

// Matrix — пересборка closure-матрицы и вердикта.
phase('Matrix')
log('Matrix: пересборка closure-матрицы 103 находок + вердикт release-readiness')
const matrix = await agent(matrixPrompt, { label: 'exec:P5.3-rebuild', phase: 'Matrix', model: 'sonnet', effort: 'medium', schema: EXEC_SCHEMA })
let verdict = null
if (!matrix || matrix.escalation || matrix.status === 'blocked') {
  queue('P5.3-matrix', `матрица не пересобрана: ${matrix?.summary ?? 'агент умер'}`, [])
} else {
  const verify = await agent(matrixVerify, { label: 'verify:P5.3', phase: 'Matrix', model: 'opus', effort: 'high', schema: MATRIX_SCHEMA })
  if (!verify) {
    queue('P5.3-matrix', 'верификатор матрицы умер', [])
  } else {
    verdict = verify.verdict
    if (verify.missing.length || verify.rowsFound !== verify.expected || !verify.verdictHonest) {
      log(`Matrix: расхождение — строк ${verify.rowsFound}/${verify.expected}, дефектов ${verify.missing.length}, честен: ${verify.verdictHonest}`)
      const repaired = await agent(
        `${COLD_START}\n\nВерификатор матрицы нашёл дефекты: строк ${verify.rowsFound}/${verify.expected}, ` +
          `пропущено/фиктивно: ${verify.missing.join(', ') || '—'}, вердикт честен: ${verify.verdictHonest}. ` +
          `${verify.notes ?? ''}\nПочини P5.3-closure-matrix.md: добери F-id, сними «закрыт» без живого ` +
          `коммита/теста, приведи вердикт в соответствие с фактом. Коммит: ` +
          `\`docs(plan): починка closure-матрицы по замечаниям верификатора (P5.3)\`.`,
        { label: 'repair:P5.3', phase: 'Matrix', model: 'opus', effort: 'high', schema: EXEC_SCHEMA },
      )
      if (repaired && !repaired.escalation) closed.push('P5.3-matrix')
      else queue('P5.3-matrix', `матрица не сошлась: ${verify.rowsFound}/${verify.expected}`, [])
    } else {
      closed.push('P5.3-matrix')
      log(`✓ Matrix: сошлась ${verify.rowsFound}/${verify.expected}; вердикт: ${verify.verdict}`)
    }
  }
}

log(`Хвосты добиты: закрыто ${closed.length} (${closed.join(', ')}); человеку ${queued.length}`)

return {
  closed,
  queued,
  verdict,
  statuses: { 'P4.1': r41, 'P4.9': r49, 'P4.10': r410 },
  nextStep: queued.length
    ? 'Часть хвостов ушла человеку — разбери queued[]. После закрытия — /task:plan-close archive VLT-REMED.'
    : `VLT-REMED реализован до конца. Вердикт release-readiness: ${verdict ?? '—'}. ` +
      'Если ГОТОВ — /task:plan-close archive VLT-REMED; отдельно решить CHANGELOG deny-правило.',
}

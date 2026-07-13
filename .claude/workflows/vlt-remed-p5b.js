export const meta = {
  name: 'vlt-remed-p5b',
  description: 'Догон P5 VLT-REMED после ревью: design P5.2 (расширение скоупа под R-01) → P5.2 ремедиация 10 находок + review-гейт → re-sweep opus/xhigh → P5.3 closure-матрица + release-readiness.',
  whenToUse: 'P5.1 уже сдал 10 находок; довести P5.2/P5.3 автономно.',
  phases: [
    { title: 'Design', detail: 'ТЗ P5.2 под решение по R-01 (opus/high)' },
    { title: 'P5.2', detail: 'ремедиация 10 находок + review-гейт (opus/high)' },
    { title: 'Re-sweep', detail: 'контрольное adversarial-ревью всего диффа (opus/xhigh)' },
    { title: 'P5.3', detail: 'closure-матрица 103 находок + release-readiness' },
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

const DESIGN_SCHEMA = {
  type: 'object',
  required: ['phase', 'itemsDetailed', 'dorClosed'],
  properties: {
    phase: { type: 'string' },
    itemsDetailed: { type: 'integer' },
    dorClosed: { type: 'boolean' },
    notes: { type: 'string' },
  },
}

const MATRIX_SCHEMA = {
  type: 'object',
  required: ['rowsFound', 'expected', 'missing', 'verdictHonest'],
  properties: {
    rowsFound: { type: 'integer' },
    expected: { type: 'integer' },
    missing: { type: 'array', items: { type: 'string' } },
    verdictHonest: { type: 'boolean' },
    notes: { type: 'string' },
  },
}

const COLD_START = `
Ты работаешь в репозитории /home/vostrikov/projects/packages/vaulter, ветка remediation/audit-vlt (D8).
SSOT плана: plans/VLT-REMED/plan.md, plans/VLT-REMED/handoff.md, plans/VLT-REMED/phases/P5.md.
Находки сквозного ревью P5.1 (ВХОД для P5.2): plans/VLT-REMED/findings/P5.1-review-findings.md — 10 находок
(1 Blocker · 6 Major · 3 Minor), все CONFIRMED. Реестр AUDIT-VLT (append-only, НЕ править):
plans/AUDIT-VLT/findings/REGISTER.md (103 находки). Открытые вопросы: plans/VLT-REMED/MORNING-QUEUE.md.

СОСТОЯНИЕ: закрыто 23 items (P0.1–P0.3, P1.1–P1.4, P2.1–P2.3, P3.1–P3.6, P4.2–P4.8).
P4.1 — 🔴 (эскалация hocuspocus 2.x→4.x). P4.9/P4.10 — НЕ ИСПОЛНЯЛИСЬ (namespace-рефакторинга в диффе НЕТ).
CHANGELOG.md заблокирован deny-правилом харнесса — BC-записи ведём в UPGRADE.md.

⚑ РЕШЕНИЕ ВЛАДЕЛЬЦА по R-01 (Blocker) — принято, исполнять как данность, НЕ переобсуждать:
Контракт TipTap-контента = «синхрон + конфиг-шов + decay»:
  1. Store-time allow-list узлов/марок ДОЛЖЕН включать набор, который пакет сам декларирует
     через #[TiptapExtensions] (StarterKit, Image, Table, Link, Underline и т.п.) — сейчас он их режет,
     и это ломает собственный редактор пакета.
  2. Allow-list выносится в КОНФИГ (шов расширения): хост обязан иметь возможность расширить его
     под свои extensions. Сейчас это private const без шва — так нельзя.
  3. На ЧТЕНИИ — decay-to-empty / strip неизвестных узлов, как и обещает докблок
     NodeDocumentVersion::tipTapContent() («decays to an empty doc rather than throwing»), а НЕ throw.
     Сейчас существующий контент падает на чтении — расхождение код≠докблок.
  4. XSS-барьер при этом сохраняется: расширение allow-list не отменяет санитизацию.
  5. Это ломающее изменение публичной поверхности → обязательная запись в UPGRADE.md.

Жёсткие правила:
- Гейт из корня: composer lint:check && composer analyse && composer test && composer test:types.
  Затронуты documents/drive/media/filament — гони и их: \`cd packages/<pkg> && composer analyse && composer test && composer test:types\`.
  Красный lint:check ТОЛЬКО на plans/AUDIT-VLT/artifacts/repro/** — пред-существующий долг вне scope.
- На каждую находку — регрессионный тест, КРАСНЫЙ на коде ДО фикса, с id находки (R-xx) в описании (D7).
- Тип коммита ЛАТИНИЦЕЙ (feat/fix/docs/refactor/test/ci/chore) — кириллица ломает SemVer-оракул.
- D11: ревьюер P5.1 в ремедиации не участвует.
`.trim()

const designPrompt = `
${COLD_START}

ЗАДАЧА: детализируй item P5.2 (ремедиация 10 находок сквозного ревью) и закрой DoR.

ПРИЧИНА развёртки: P5.1 эскалировал — находка R-01 требует решения по ПУБЛИЧНОМУ КОНТРАКТУ
TipTap-контента и выходит за исходный Scope Included P5.2 («фикс — минимальный»).
Решение владельца принято (см. ⚑ выше) — твоя работа: РАСШИРИТЬ Scope Included P5.2 так, чтобы
R-01 чинился по этому решению (синхрон allow-list + конфиг-шов + decay-on-read + запись в UPGRADE.md),
и расписать ТЗ на все 10 находок (R-01…R-10) — по 16 полей item'а.

Обязательно в Code Guidance P5.2:
- R-02/R-03 (tus-лок не покрывает sweep; append() fail-OPEN) — это Blocker-класс: барьер обязан
  стоять на пути ДОСТАВКИ вреда (sweep/expire под тем же локом, что и append), а не рядом с ним.
  Регресс-тест — на реальную гонку, а не на «метод вызвался».
- R-05 (follow_redirects=true снимает SSRF-барьер целиком) — проверить, что фикс реально гасит
  редирект-вектор, а не только конфиг-флаг.
- R-04 (markdown-экспорт экранирует HTML, а не Markdown) — отклонять КЛАСС (javascript:/data:/vbscript:
  и прочие опасные схемы в markdown-ссылках), а не конкретную строку из теста.
- R-07 (второй эшелон XSS недостижим и без red-before-теста) — либо сделать достижимым с красным
  тестом, либо честно признать одноэшелонность и записать это в план (не притворяться).
- Каждая находка обязана получить регресс-тест, который ГОНЯЕТСЯ в CI (не осиротел в неподключённом
  лейне/директории — как было с TusConcurrentPatchTest).

Сделай это, вызвав скилл: Skill(skill="task:plan-design", args="VLT-REMED P5.2").
Итог закоммить. Кода приложения НЕ пиши.
`.trim()

const execP52 = `
${COLD_START}

ЗАДАЧА: исполни item P5.2 мастер-плана VLT-REMED по полному plan-протоколу (цикл 1–8) —
ремедиация всех 10 находок сквозного ревью (plans/VLT-REMED/findings/P5.1-review-findings.md).

Blocker/Major (R-01…R-07) — ОБЯЗАТЕЛЬНЫЙ фикс с регрессионным тестом (красный ДО фикса, id находки
в описании). Minor — фикс либо явное «принять» с ОБОСНОВАНИЕМ в Completion Notes (не отписка).
R-01 чини строго по решению владельца (⚑ выше) — контракт уже выбран, не переобсуждай.

Закрытие обязательно: коммит(ы) Conventional Commits (тип латиницей), статус item'а,
Completion Notes/Pending Work/Known Deviations, синхронизация \`## Phase Status\` и Status Board,
строка в \`## 6. Update Log\`, перезапись handoff.md, прогон plan-lint.py.

Сделай это, вызвав скилл: Skill(skill="task:plan-exec", args="VLT-REMED P5.2").
escalation=true — ТОЛЬКО если упрёшься в новое решение человека, которого нет в ⚑.
`.trim()

const reviewP52 = `
Read-only adversarial-ревью item'а P5.2 плана VLT-REMED (ветка remediation/audit-vlt) — ремедиация
10 находок сквозного ревью. Вход: plans/VLT-REMED/findings/P5.1-review-findings.md (R-01…R-10).
Дифф: \`git log --oneline -12\`, коммиты с "(P5.2)". Кода НЕ меняй.

Оси:
1. Каждая находка R-01…R-07 (Blocker/Major) РЕАЛЬНО закрыта кодом — не переписана в Pending Work.
   Пройди по списку поимённо, отметь незакрытые.
2. «Зелёное, но не починенное»: регресс-тест на находку правда красный на коде ДО фикса
   (проверь выборочно git stash/revert хотя бы на R-01, R-02/R-03, R-05) и реально ГОНЯЕТСЯ в CI.
3. R-01: allow-list теперь включает узлы из #[TiptapExtensions]? Есть конфиг-шов? На чтении decay,
   а не throw? XSS-барьер НЕ ослаблен (расширение allow-list ≠ дыра)? Запись в UPGRADE.md есть?
4. R-02/R-03: лок реально накрывает sweep/expire, а не только append? append() больше не fail-OPEN?
5. Спец-код под тест: отклоняется КЛАСС дефекта, а не строка из теста.
6. Публичный контракт/SemVer, ServiceProvider register-vs-boot, publishables, scope drift.

Гоняй проверки: vendor/bin/phpstan analyse, целевые pest-тесты. Верни только находки.
`.trim()

const autoFix = (findings) => `
${COLD_START}

Ревью P5.2 нашло дефекты. Почини ОДНОЗНАЧНОЕ строго в рамках Scope Included P5.2:

${findings.map((f, i) => `${i + 1}. [${f.severity}] ${f.file}${f.line ? ':' + f.line : ''} — ${f.summary}`).join('\n')}

ПРАВИЛА: чини механическое/однозначное (покрытие тестом, CI-гейт, узкий фикс, docs/UPGRADE).
Если нужен НОВЫЙ архитектурный/SemVer-выбор, которого нет в ⚑, или выход за Scope — НЕ хакай тест,
НЕ импровизируй: верни escalation=true с объяснением.
Фиксы — ОТДЕЛЬНЫМ коммитом \`fix(scope): суть по замечаниям ревью (P5.2)\` (тип латиницей).
Гейт зелёный ДО коммита.
`.trim()

const resweepPrompt = `
${COLD_START}

КОНТРОЛЬНОЕ сквозное adversarial-ревью ВСЕГО диффа ветки remediation/audit-vlt (6b5a52e..HEAD).
Read-only: код НЕ меняй, ничего не коммить.

ЗАЧЕМ: P5.1 в своих Known Deviations признал, что мог исполниться на пониженном routing
(sonnet/medium вместо предписанного opus/xhigh) и «мог не увидеть находок». Ты — страховка.
P5.2 уже починил 10 находок P5.1 — ИХ не пересматривай, ищи ПРОПУЩЕННОЕ.

Оси (профиль code):
1. Три Blocker'а AUDIT-VLT (F-P1.4-01 tus-гонка, F-P1.7-01 SSRF, F-P1.11-01 XSS) — барьер стоит на
   пути ДОСТАВКИ вреда, а не наблюдения? Проверь ИСПОЛНЕНИЕМ (revert барьера → тест краснеет?),
   а не чтением. P5.1 уже поймал, что tus-лок не крыл sweep, а SSRF обходился редиректом —
   ищи такие же обходные пути, которых он не заметил.
2. «Зелёное, но не починенное»: регресс-тесты, проходящие и на дефектном коде; тесты-сироты,
   не подключённые в CI-лейн.
3. Публичная поверхность/SemVer: всё ломающее покрыто UPGRADE.md?
4. ServiceProvider register-vs-boot, publishables.
5. Кросс-пакетные швы: core↔drive↔documents↔filament — не разъехались ли контракты.

Каждая находка — с file:line и ДОКАЗАТЕЛЬСТВОМ (исполнением, не рассуждением). Верни только находки.
`.trim()

const execP53 = `
${COLD_START}

ЗАДАЧА: исполни item P5.3 — closure-матрица и release-readiness.

Построй plans/VLT-REMED/findings/P5.3-closure-matrix.md: строка на КАЖДУЮ из 103 находок REGISTER.md —
\`F-id | батч | item | коммит (sha) | тест | статус (закрыт/принят/отложен)\`.
Машинная сверка: счётчик 103, ноль пропусков.

ЧЕСТНОСТЬ ВАЖНЕЕ КРАСИВОЙ ЦИФРЫ: НЕ пиши «закрыт» там, где нет живого коммита и живого теста —
честный «отложен»/«принят с обоснованием» лучше лжи. Отдельно отрази:
- P4.1 (🔴, эскалация hocuspocus 2.x→4.x),
- P4.9/P4.10 (не исполнялись),
- находки P5.1 (R-01…R-10) и их закрытие в P5.2.
Вердикт release-readiness обязан учитывать незакрытые items (ожидаемо «НЕ готов к релизу» —
это КОРРЕКТНЫЙ результат, а не провал; не подгоняй его под «готов»).

Сделай это, вызвав скилл: Skill(skill="task:plan-exec", args="VLT-REMED P5.3").
`.trim()

const matrixVerify = `
${COLD_START}

Ты — МАШИННЫЙ ВЕРИФИКАТОР closure-матрицы P5.3 (plans/VLT-REMED/findings/P5.3-closure-matrix.md).
Read-only, ничего не чини. Проверяй ФАКТАМИ, не на веру:

1. Число строк матрицы == число находок в plans/AUDIT-VLT/findings/REGISTER.md (ожидается 103).
   Посчитай ОБА сам (grep/awk по F-id). Верни rowsFound и expected.
2. Ни один F-id из REGISTER.md не пропущен — список пропущенных в missing[].
3. Каждая строка «закрыт» ссылается на РЕАЛЬНО СУЩЕСТВУЮЩИЙ коммит (\`git cat-file -e <sha>\`)
   и РЕАЛЬНО СУЩЕСТВУЮЩИЙ тест (файл есть; тест с F-id в описании находится grep'ом).
   Строка «закрыт» без живого коммита или теста — ЛОЖЬ: верни в missing[] как "fake-closed: <F-id>".
4. Вердикт release-readiness ЧЕСТЕН? P4.1 (🔴), P4.9/P4.10 (не исполнялись) обязаны быть отражены
   как открытые, вердикт — «НЕ готов к релизу». Если матрица рисует «готов» — verdictHonest=false.
`.trim()

// ─── Прогон ─────────────────────────────────────────────────────────────────
const REVIEW_ROUNDS = 3
const closed = []
const queued = []
const queue = (item, reason, findings) => {
  queued.push({ item, reason, findings: findings ?? [] })
  log(`🟠 ${item}: человеку — ${reason}`)
}

// ── Design: расширить ТЗ P5.2 под решение по R-01 ───────────────────────────
phase('Design')
log('P5.2: развёртка ТЗ под решение владельца по R-01 (opus/high)')
const design = await agent(designPrompt, {
  label: 'design:P5.2',
  phase: 'Design',
  model: 'opus',
  effort: 'high',
  schema: DESIGN_SCHEMA,
})
if (!design || !design.dorClosed) {
  queue('P5.2-design', 'plan-design не закрыл DoR', [])
  return { closed, queued, nextStep: 'Развёртку P5.2 разбирай вручную: /task:plan-design VLT-REMED P5.2' }
}
log('P5.2: ТЗ развёрнуто, DoR закрыт')

// ── P5.2: ремедиация 10 находок + review-гейт ───────────────────────────────
phase('P5.2')
log('P5.2: ремедиация 10 находок (opus/high)')
const p52 = await agent(execP52, {
  label: 'exec:P5.2',
  phase: 'P5.2',
  model: 'opus',
  effort: 'high',
  schema: EXEC_SCHEMA,
})

let p52Clean = false
if (!p52) {
  queue('P5.2', 'исполнитель умер', [])
} else if (p52.status === 'blocked') {
  queue('P5.2', `blocked: ${p52.summary}`, [])
} else {
  // Эскалация тут НЕ роняет прогон: фиксируем и всё равно ревьюим сделанное.
  if (p52.escalation) queue('P5.2-escalation', `исполнитель эскалировал: ${p52.summary}`, [])

  let lastHard = []
  for (let round = 1; round <= REVIEW_ROUNDS; round++) {
    const review = await agent(reviewP52, {
      label: `review:P5.2${round > 1 ? `#${round}` : ''}`,
      phase: 'P5.2',
      agentType: 'vaulter-reviewer',
      model: 'opus',
      effort: 'high',
      schema: REVIEW_SCHEMA,
    })
    if (!review) break

    const hard = review.findings.filter((f) => f.severity === 'Blocker' || f.severity === 'Major')
    lastHard = hard
    if (hard.length === 0) {
      p52Clean = true
      log(`✓ P5.2: ревью чистое (Minor/Nit: ${review.findings.length})`)
      break
    }
    if (round === REVIEW_ROUNDS) break

    log(`P5.2: ${hard.length} Blocker/Major — авто-фикс раунд ${round}`)
    const fixed = await agent(autoFix(hard), {
      label: `fix:P5.2#${round}`,
      phase: 'P5.2',
      model: 'opus',
      effort: 'high',
      schema: EXEC_SCHEMA,
    })
    if (!fixed || fixed.escalation) {
      if (fixed?.escalation) log('P5.2: авто-фикс эскалировал — человеку')
      break
    }
  }

  if (p52Clean) closed.push('P5.2')
  else queue('P5.2', `ревью не сошлось после авто-фиксов (осталось ${lastHard.length} Blocker/Major)`, lastHard)
}

// ── Re-sweep: контрольное ревью всего диффа на opus/xhigh ───────────────────
phase('Re-sweep')
log('Re-sweep: контрольное adversarial-ревью всего диффа (opus/xhigh) — страховка от недо-роутинга P5.1')
const resweep = await agent(resweepPrompt, {
  label: 'resweep:full-diff',
  phase: 'Re-sweep',
  agentType: 'vaulter-reviewer',
  model: 'opus',
  effort: 'xhigh',
  schema: REVIEW_SCHEMA,
})

if (resweep) {
  const hard = resweep.findings.filter((f) => f.severity === 'Blocker' || f.severity === 'Major')
  if (hard.length === 0) {
    log(`✓ Re-sweep: пропущенных Blocker/Major нет (Minor/Nit: ${resweep.findings.length})`)
  } else {
    log(`Re-sweep: нашёл ${hard.length} ПРОПУЩЕННЫХ Blocker/Major — один раунд ремедиации`)
    const fixed = await agent(autoFix(hard), {
      label: 'fix:resweep',
      phase: 'Re-sweep',
      model: 'opus',
      effort: 'high',
      schema: EXEC_SCHEMA,
    })
    if (!fixed || fixed.escalation) {
      queue('Re-sweep', `пропущенные находки требуют человека: ${fixed?.summary ?? 'агент умер'}`, hard)
    } else {
      const recheck = await agent(reviewP52, {
        label: 'review:resweep-fix',
        phase: 'Re-sweep',
        agentType: 'vaulter-reviewer',
        model: 'opus',
        effort: 'high',
        schema: REVIEW_SCHEMA,
      })
      const stillHard = (recheck?.findings ?? []).filter((f) => f.severity === 'Blocker' || f.severity === 'Major')
      if (stillHard.length) queue('Re-sweep', `после фикса осталось ${stillHard.length} Blocker/Major`, stillHard)
      else log('✓ Re-sweep: пропущенные находки закрыты')
    }
  }
} else {
  queue('Re-sweep', 'контрольный ревьюер умер — покрытие не подтверждено', [])
}

// ── P5.3: closure-матрица + release-readiness ───────────────────────────────
phase('P5.3')
log('P5.3: closure-матрица 103 находок + вердикт release-readiness')
const p53 = await agent(execP53, {
  label: 'exec:P5.3',
  phase: 'P5.3',
  model: 'sonnet',
  effort: 'medium',
  schema: EXEC_SCHEMA,
})

if (!p53 || p53.status === 'blocked') {
  queue('P5.3', `матрица не построена: ${p53?.summary ?? 'агент умер'}`, [])
} else {
  const verify = await agent(matrixVerify, {
    label: 'verify:P5.3-matrix',
    phase: 'P5.3',
    model: 'opus',
    effort: 'high',
    schema: MATRIX_SCHEMA,
  })

  if (!verify) {
    queue('P5.3', 'верификатор матрицы умер — матрица не подтверждена', [])
  } else if (verify.missing.length || verify.rowsFound !== verify.expected || !verify.verdictHonest) {
    log(`P5.3: матрица не сошлась — строк ${verify.rowsFound}/${verify.expected}, дефектов ${verify.missing.length}, вердикт честен: ${verify.verdictHonest}`)
    const repaired = await agent(
      `${COLD_START}\n\nВерификатор closure-матрицы P5.3 нашёл дефекты:\n` +
        `- строк: ${verify.rowsFound}, ожидалось: ${verify.expected}\n` +
        `- пропущено / фиктивно закрыто: ${verify.missing.join(', ') || '—'}\n` +
        `- вердикт release-readiness честен: ${verify.verdictHonest}\n` +
        `${verify.notes ? `- заметки: ${verify.notes}\n` : ''}\n` +
        `Почини plans/VLT-REMED/findings/P5.3-closure-matrix.md: добери пропущенные F-id, сними «закрыт»\n` +
        `там, где нет живого коммита/теста (ставь честный «отложен»/«принят» с обоснованием), приведи вердикт\n` +
        `в соответствие с фактом (P4.1 🔴, P4.9/P4.10 не исполнялись).\n` +
        `Коммит: \`docs(plan): починка closure-матрицы по замечаниям верификатора (P5.3)\`.`,
      { label: 'repair:P5.3-matrix', phase: 'P5.3', model: 'opus', effort: 'high', schema: EXEC_SCHEMA },
    )
    if (repaired && !repaired.escalation) {
      closed.push('P5.3')
      log('✓ P5.3: матрица починена')
    } else {
      queue('P5.3', `матрица не сошлась: строк ${verify.rowsFound}/${verify.expected}`, [])
    }
  } else {
    closed.push('P5.3')
    log(`✓ P5.3: матрица сошлась — ${verify.rowsFound}/${verify.expected}, вердикт честен`)
  }
}

log(`P5 добита: закрыто ${closed.length}, человеку ${queued.length}`)

return {
  closed,
  queued,
  nextStep: queued.length
    ? 'Разбери queued[]. Плюс открыты P4.1 / P4.9 / P4.10 (MORNING-QUEUE.md).'
    : 'P5 закрыта. Остаются P4.1 / P4.9 / P4.10 (MORNING-QUEUE.md), затем /task:plan-close archive VLT-REMED.',
}

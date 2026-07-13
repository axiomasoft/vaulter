export const meta = {
  name: 'vlt-remed-p5',
  description: 'Автономный догон фазы P5 VLT-REMED: design → P5.1 сквозное adversarial-ревью + completeness-critic → P5.2 ремедиация находок с review-гейтом → P5.3 closure-матрица 103 находок и release-readiness.',
  whenToUse: 'Финальная фаза VLT-REMED без человека: ревью всей ремедиации, починка находок, доказательство закрытия.',
  phases: [
    { title: 'Design', detail: 'развёртка скелета P5 (opus/high)' },
    { title: 'P5.1', detail: 'сквозное adversarial-ревью диффа (opus/xhigh, read-only) + критик полноты' },
    { title: 'P5.2', detail: 'ремедиация находок ревью + review-гейт' },
    { title: 'P5.3', detail: 'closure-матрица 103 находок + release-readiness' },
  ],
}

// ─── Схемы ──────────────────────────────────────────────────────────────────
const EXEC_SCHEMA = {
  type: 'object',
  required: ['item', 'status', 'escalation', 'summary'],
  properties: {
    item: { type: 'string' },
    status: { enum: ['done', 'done_with_deviations', 'blocked'] },
    escalation: { type: 'boolean' },
    summary: { type: 'string' },
    commits: { type: 'array', items: { type: 'string' } },
    artifacts: { type: 'array', items: { type: 'string' } },
    blockers: { type: 'integer' },
    majors: { type: 'integer' },
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

const CRITIC_SCHEMA = {
  type: 'object',
  required: ['adequate', 'gaps'],
  properties: {
    adequate: { type: 'boolean' },
    gaps: {
      type: 'array',
      items: {
        type: 'object',
        required: ['gap', 'why'],
        properties: {
          gap: { type: 'string' },
          why: { type: 'string' },
        },
      },
    },
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

// ─── Общий контекст ─────────────────────────────────────────────────────────
const COLD_START = `
Ты работаешь в репозитории /home/vostrikov/projects/packages/vaulter, ветка remediation/audit-vlt (D8).
SSOT плана: plans/VLT-REMED/plan.md, plans/VLT-REMED/handoff.md, plans/VLT-REMED/phases/<Pn>.md.
Реестр находок AUDIT-VLT (append-only, НЕ править): plans/AUDIT-VLT/findings/REGISTER.md — 103 находки.
Открытые вопросы ночного прогона: plans/VLT-REMED/MORNING-QUEUE.md.

СОСТОЯНИЕ РЕМЕДИАЦИИ на входе в P5 (важно, не проглядеть):
- Закрыто 23 items: P0.1–P0.3, P1.1–P1.4, P2.1–P2.3, P3.1–P3.6, P4.2–P4.8.
- P4.1 — 🔴 Blocked: 13/14 находок закрыты, эскалация по F-P4.5-02 (hocuspocus 2.x→4.x
  ломает регресс-тест websocket-hardening — реальный сдвиг контракта; бамп реверчен).
- P4.9 (канон структуры + ADR) и P4.10 (массовый namespace-refactor) — НЕ ИСПОЛНЯЛИСЬ.
  Значит: ось «поведенческая нейтральность P4.10» в P5.1 неприменима — рефакторинга в диффе НЕТ.
  Не выдумывай его наличие; отметь как n/a.
- CHANGELOG.md заблокирован deny-правилом харнесса — BC-записи велись только в UPGRADE.md.

Жёсткие правила плана:
- Гейт валидации из корня: composer lint:check && composer analyse && composer test && composer test:types.
  Если item трогает documents/drive/media/filament — гони и их собственные
  \`cd packages/<pkg> && composer analyse && composer test && composer test:types\`.
  Красный lint:check ТОЛЬКО на plans/AUDIT-VLT/artifacts/repro/** — известный пред-существующий долг вне scope.
- На каждый F-id — регрессионный тест, КРАСНЫЙ на коде до фикса, с F-id в описании (D7).
- D11: ревьюер (P5.1) свои находки НЕ чинит — их чинит P5.2.
- D13: severity/партиция из REGISTER.md потребляются as-is, не переоцениваются.
- План разошёлся с кодом → НЕ импровизировать, эскалация.
`.trim()

const execPrompt = (item, extra = '') => `
${COLD_START}

ЗАДАЧА: исполни item ${item} мастер-плана VLT-REMED по полному plan-протоколу (цикл 1–8),
ВКЛЮЧАЯ обязательное закрытие: коммит Conventional Commits формата \`тип(scope): суть (${item})\`
(тип — ЛАТИНИЦЕЙ: feat/fix/docs/refactor/test/ci/chore — не «фикс»/«докс», это ломает SemVer-оракул),
статус item'а → 🟢/🟠/🔴, Completion Notes/Pending Work/Known Deviations, синхронизация
\`## Phase Status\` и \`## 4. Phase Index & Status Board\`, строка в \`## 6. Update Log\`,
полная перезапись plans/VLT-REMED/handoff.md, прогон plan-lint.py.

Сделай это, вызвав скилл: Skill(skill="task:plan-exec", args="VLT-REMED ${item}").
Если item уже в терминальном статусе — НЕ переисполняй, верни status по факту с commits=[].
${extra}

Верни структурированный результат. escalation=true, если протокол выдал ESCALATION-REQUIRED.
`.trim()

const designPrompt = `
${COLD_START}

ЗАДАЧА: разверни СКЕЛЕТ-фазу P5 мастер-плана VLT-REMED — детализируй все 3 её items
(P5.1/P5.2/P5.3, по 16 полей каждый) и закрой DoR-гейт. Блок «Обязательные оси» в Phase Context
скелета P5 — вход развёртывания, а не пожелание: перенеси все 6 осей ревью (а–е) в Code Guidance P5.1.

ВАЖНАЯ ПОПРАВКА К СКЕЛЕТУ: Phase Context P5 написан в расчёте на 29 закрытых items, включая
namespace-refactor P4.10. Фактически P4.9/P4.10 НЕ исполнялись, а P4.1 — 🔴 Blocked.
При развёртке:
- ось (д) «поведенческая нейтральность P4.10» в P5.1 — пометь n/a с обоснованием (рефакторинга в диффе нет);
- P5.3 обязан честно отразить P4.1/P4.9/P4.10 как отложенные, а вердикт release-readiness —
  учитывать их незакрытость (ожидаемо «не готов к релизу», и это корректный результат, не провал).

Сделай это, вызвав скилл: Skill(skill="task:plan-design", args="VLT-REMED P5").
Итог закоммить. Кода приложения НЕ пиши.
`.trim()

const criticPrompt = `
${COLD_START}

Ты — КРИТИК ПОЛНОТЫ ревью P5.1 (не ревьюер кода). Read-only. Ничего не чини и не коммить.
P5.1 только что провёл сквозное adversarial-ревью диффа ветки remediation/audit-vlt.
Его артефакт — в plans/VLT-REMED/findings/ (найди файл P5.1-* / отчёт ревью) + Completion Notes в phases/P5.md.

Твой вопрос ОДИН: ревью реально покрыло дифф — или сделало вид?

Проверь механически, а не на веру:
1. Охват. \`git diff --stat <точка ветвления>..HEAD\` — какие пакеты/файлы реально изменены?
   Сверь со списком того, что ревью РАЗБИРАЛО. Пропущенные пакеты/крупные файлы = gap.
2. Три Blocker'а (F-P1.4-01, F-P1.7-01, F-P1.11-01) — ревью проверило их фиксы ПРЕДМЕТНО
   (барьер на пути доставки вреда), или ограничилось «тест зелёный»? Отсутствие предметной
   проверки хотя бы одного Blocker'а = gap.
3. Ось (б): выборочная проверка «регрессионный тест красный на коде ДО фикса» —
   ревью реально делала git stash/revert-проверку хоть на одном тесте, или поверила описанию? = gap.
4. Ось (е) «зелёное, но не починенное»: ревью проверило, что регресс-тесты действительно
   ГОНЯЮТСЯ в CI (не осиротели — как было с TusConcurrentPatchTest в P0.3)? = gap.
5. Публичная поверхность/SemVer и ServiceProvider register-vs-boot — разобраны? = gap.

Верни adequate=false и список конкретных gaps, если ревью поверхностное. Не придирайся к стилю —
только к ПРОПУСКАМ покрытия. Если покрытие честное — adequate=true, gaps=[].
`.trim()

const supplementPrompt = (gaps) => `
${COLD_START}

Критик полноты нашёл ПРОПУСКИ в сквозном ревью P5.1. Закрой их — read-only, код НЕ меняй:

${gaps.map((g, i) => `${i + 1}. ${g.gap} — почему важно: ${g.why}`).join('\n')}

Проведи ДОРЕВЬЮ строго по этим пропускам (профиль code, adversarial). Каждая находка — с file:line
и доказательством. Для находок ≥ Major проверь достижимость реальным путём, а не рассуждением.

Итог — ДОПОЛНИ артефакт ревью P5.1 в plans/VLT-REMED/findings/ (не перезаписывай найденное ранее,
допиши раздел «Дополнение по пропускам критика полноты») и закоммить как
\`docs(review): дополнение P5.1 по пропускам критика полноты (P5.1)\`.
Верни находки.
`.trim()

const reviewPrompt = (item) => `
Read-only adversarial-ревью диффа item'а ${item} плана VLT-REMED (ветка remediation/audit-vlt).
Дифф: \`git log --oneline -10\` найди коммиты этого item'а (subject содержит "(${item})"), смотри \`git show\`.
Кода НЕ меняй.

${item} — это РЕМЕДИАЦИЯ НАХОДОК СКВОЗНОГО РЕВЬЮ. Оси:
1. Каждая находка ревью P5.1 уровня Blocker/Major действительно ЗАКРЫТА кодом, а не переписана
   в Pending Work без фикса. Сверь список находок P5.1 (plans/VLT-REMED/findings/) с диффом P5.2.
2. «Зелёное, но не починенное»: регресс-тест на находку правда был бы красным на коде ДО фикса?
   Он ГОНЯЕТСЯ в CI (не осиротел в неподключённой директории/лейне)?
3. Спец-код под тест: отклоняется КЛАСС дефекта, а не конкретная строка из теста.
4. Minor/Nit: если помечены «принять» — обоснование реальное, а не отписка?
5. Публичный контракт/SemVer, ServiceProvider register-vs-boot, publishables.
6. Scope drift: не уехало ли что-то за Scope Included item'а.

Гоняй проверки: vendor/bin/phpstan analyse, целевые pest-тесты. Верни только находки.
`.trim()

const autoFixPrompt = (item, findings) => `
${COLD_START}

Ревью item'а ${item} нашло дефекты. Почини те, что чинятся ОДНОЗНАЧНО и СТРОГО в рамках Scope Included:

${findings.map((f, i) => `${i + 1}. [${f.severity}] ${f.file}${f.line ? ':' + f.line : ''} — ${f.summary}`).join('\n')}

ПРАВИЛА АВТОНОМНОГО РЕЖИМА (человека рядом нет, спорное решаем не мы):
- Чини ТОЛЬКО механическое/однозначное: покрытие тестом, CI-гейт, узкий скоуп-фикс, docs/UPGRADE.
- Если нужен АРХИТЕКТУРНЫЙ / SemVer-политический / дизайн-выбор, выход за Scope, или откат ранее
  принятого проектного решения — НЕ хакай тест, НЕ импровизируй, НЕ расширяй скоуп молча.
  Верни escalation=true с объяснением — это уйдёт в очередь человеку.
- Фиксы — ОТДЕЛЬНЫМ коммитом (НЕ amend), \`fix(scope): суть по замечаниям ревью (${item})\`.
  Тип коммита ЛАТИНИЦЕЙ. Гейт валидации зелёный ДО коммита.
`.trim()

const matrixVerifyPrompt = `
${COLD_START}

Ты — МАШИННЫЙ ВЕРИФИКАТОР closure-матрицы P5.3. Read-only, ничего не чини.
Артефакт: plans/VLT-REMED/findings/P5.3-closure-matrix.md.

Проверь ФАКТАМИ, а не на веру:
1. Число строк матрицы == числу находок в plans/AUDIT-VLT/findings/REGISTER.md (ожидается 103).
   Посчитай оба сам (grep/awk по F-id). Верни rowsFound и expected.
2. Ни один F-id из REGISTER.md не пропущен в матрице. Список пропущенных — в missing[].
3. Каждая строка со статусом «закрыт» ссылается на РЕАЛЬНО СУЩЕСТВУЮЩИЙ коммит
   (\`git cat-file -e <sha>\`) и РЕАЛЬНО СУЩЕСТВУЮЩИЙ тест (файл есть, тест с F-id в описании находится
   grep'ом). Строка «закрыт» без живого коммита или без теста — это ЛОЖЬ в матрице, верни в missing[]
   с пометкой (fake-closed: <F-id>).
4. Вердикт release-readiness ЧЕСТЕН? P4.1 (🔴, hocuspocus-эскалация), P4.9 и P4.10 (не исполнялись)
   обязаны быть отражены как открытые/отложенные, а вердикт — «НЕ готов к релизу».
   Если матрица рисует «готов» — verdictHonest=false.

verdictHonest=true только если п.4 соблюдён.
`.trim()

// ─── Прогон ─────────────────────────────────────────────────────────────────
const REVIEW_ROUNDS = 3
const queued = []
const closed = []
const queue = (item, reason, findings) => {
  queued.push({ item, reason, findings: findings ?? [] })
  log(`🟠 ${item}: отложено человеку — ${reason}`)
}

// ── Design: развернуть скелет P5 ────────────────────────────────────────────
phase('Design')
log('P5: скелет — разворачиваю через plan-design (opus/high)')
const design = await agent(designPrompt, {
  label: 'design:P5',
  phase: 'Design',
  model: 'opus',
  effort: 'high',
  schema: DESIGN_SCHEMA,
})

if (!design || !design.dorClosed) {
  log('⛔ P5: plan-design не закрыл DoR — дальше не иду')
  return {
    closed: [],
    queued: [{ item: 'P5', reason: 'plan-design не закрыл DoR — фаза требует человека', findings: [] }],
    nextStep: 'Разбери развёртку P5 вручную: /task:plan-design VLT-REMED P5',
  }
}
log(`P5 развёрнута: детализировано items ${design.itemsDetailed}`)

// ── P5.1: сквозное adversarial-ревью (read-only) + критик полноты ───────────
phase('P5.1')
log('P5.1: сквозное adversarial-ревью диффа (opus/xhigh, read-only)')
const p51 = await agent(
  execPrompt(
    'P5.1',
    'Это READ-ONLY ревью (D11): свои находки НЕ чини — их чинит P5.2. Артефакт находок положи в plans/VLT-REMED/findings/.',
  ),
  { label: 'exec:P5.1', phase: 'P5.1', model: 'opus', effort: 'xhigh', schema: EXEC_SCHEMA },
)

if (!p51 || p51.escalation || p51.status === 'blocked') {
  queue('P5.1', `ревью не отработало: ${p51?.summary ?? 'агент умер'}`, [])
  return { closed, queued, nextStep: 'P5.1 не закрылся — разбирай вручную, P5.2/P5.3 не запускались.' }
}
closed.push('P5.1')
log(`P5.1 закрыт: Blocker ${p51.blockers ?? '?'} / Major ${p51.majors ?? '?'}`)

// Критик полноты: ревью реально покрыло дифф — или сделало вид?
log('P5.1: критик полноты — проверяю, не поверхностное ли ревью')
const critic = await agent(criticPrompt, {
  label: 'critic:P5.1',
  phase: 'P5.1',
  model: 'opus',
  effort: 'high',
  schema: CRITIC_SCHEMA,
})

if (critic && !critic.adequate && critic.gaps.length) {
  log(`P5.1: критик нашёл ${critic.gaps.length} пропусков — запускаю доревью`)
  const supplement = await agent(supplementPrompt(critic.gaps), {
    label: 'supplement:P5.1',
    phase: 'P5.1',
    model: 'opus',
    effort: 'xhigh',
    schema: REVIEW_SCHEMA,
  })
  if (supplement) {
    log(`P5.1: доревью добавило находок — Blocker ${supplement.blockers} / Major ${supplement.majors}`)
  } else {
    queue('P5.1-supplement', 'доревью по пропускам критика умерло — покрытие ревью неполное', [])
  }
} else {
  log('✓ P5.1: критик полноты подтвердил покрытие')
}

// ── P5.2: ремедиация находок ревью + review-гейт ────────────────────────────
phase('P5.2')
log('P5.2: ремедиация находок ревью (opus/high — чиним Blocker/Major)')
const p52 = await agent(
  execPrompt(
    'P5.2',
    'Чини находки ревью P5.1 (артефакт в plans/VLT-REMED/findings/). Blocker/Major — обязательный фикс\n' +
      'с регрессионным тестом (красный ДО фикса, F-id/номер находки в описании). Minor/Nit — фикс либо\n' +
      'явное «принять» с ОБОСНОВАНИЕМ в Completion Notes (не отписка). Ревьюер P5.1 тут не участвует (D11).',
  ),
  { label: 'exec:P5.2', phase: 'P5.2', model: 'opus', effort: 'high', schema: EXEC_SCHEMA },
)

if (!p52) {
  queue('P5.2', 'исполнитель умер', [])
} else if (p52.escalation || p52.status === 'blocked') {
  queue('P5.2', `эскалация: ${p52.summary}`, [])
} else {
  // review-гейт: converge-or-queue
  let clean = false
  let lastHard = []
  for (let round = 1; round <= REVIEW_ROUNDS; round++) {
    const review = await agent(reviewPrompt('P5.2'), {
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
      clean = true
      log(`✓ P5.2: ревью чистое (Minor/Nit: ${review.findings.length})`)
      break
    }
    if (round === REVIEW_ROUNDS) break

    log(`P5.2: ${hard.length} Blocker/Major — авто-фикс раунд ${round}`)
    const fixed = await agent(autoFixPrompt('P5.2', hard), {
      label: `fix:P5.2#${round}`,
      phase: 'P5.2',
      model: 'opus',
      effort: 'high',
      schema: EXEC_SCHEMA,
    })
    if (!fixed) break
    if (fixed.escalation) {
      log('P5.2: авто-фикс эскалировал — человеку')
      break
    }
  }
  if (clean) closed.push('P5.2')
  else queue('P5.2', `ревью не сошлось после авто-фиксов (осталось ${lastHard.length} Blocker/Major)`, lastHard)
}

// ── P5.3: closure-матрица 103 находок + release-readiness ───────────────────
phase('P5.3')
log('P5.3: closure-матрица 103 находок + вердикт release-readiness')
const p53 = await agent(
  execPrompt(
    'P5.3',
    'Построй plans/VLT-REMED/findings/P5.3-closure-matrix.md: строка на КАЖДУЮ из 103 находок REGISTER.md —\n' +
      '`F-id | батч | item | коммит (sha) | тест | статус (закрыт/принят/отложен)`. Машинная сверка: счётчик 103,\n' +
      'ноль пропусков. НЕ рисуй «закрыт» там, где нет живого коммита и живого теста — честный «отложен» лучше лжи.\n' +
      'P4.1 (🔴), P4.9/P4.10 (не исполнялись) — обязаны быть отражены как открытые; вердикт release-readiness\n' +
      'должен это учитывать (ожидаемо «НЕ готов к релизу» — это корректный результат, а не провал).',
  ),
  { label: 'exec:P5.3', phase: 'P5.3', model: 'sonnet', effort: 'medium', schema: EXEC_SCHEMA },
)

if (!p53) {
  queue('P5.3', 'исполнитель умер', [])
} else if (p53.escalation || p53.status === 'blocked') {
  queue('P5.3', `эскалация: ${p53.summary}`, [])
} else {
  // Машинная верификация матрицы: счётчик, пропуски, fake-closed, честность вердикта
  const verify = await agent(matrixVerifyPrompt, {
    label: 'verify:P5.3-matrix',
    phase: 'P5.3',
    model: 'opus',
    effort: 'high',
    schema: MATRIX_SCHEMA,
  })

  if (!verify) {
    queue('P5.3', 'верификатор матрицы умер — матрица не подтверждена', [])
  } else if (verify.missing.length || verify.rowsFound !== verify.expected || !verify.verdictHonest) {
    log(`P5.3: матрица не сошлась — строк ${verify.rowsFound}/${verify.expected}, пропусков ${verify.missing.length}, вердикт честен: ${verify.verdictHonest}`)
    // одна попытка починить матрицу по замечаниям верификатора
    const repaired = await agent(
      `${COLD_START}\n\nВерификатор closure-матрицы P5.3 нашёл дефекты:\n` +
        `- строк в матрице: ${verify.rowsFound}, ожидалось: ${verify.expected}\n` +
        `- пропущено/фиктивно закрыто: ${verify.missing.join(', ') || '—'}\n` +
        `- вердикт release-readiness честен: ${verify.verdictHonest}\n` +
        `${verify.notes ? `- заметки: ${verify.notes}\n` : ''}\n` +
        `Почини plans/VLT-REMED/findings/P5.3-closure-matrix.md: добери пропущенные F-id, сними «закрыт»\n` +
        `там, где нет живого коммита/теста (ставь честный «отложен»/«принят» с обоснованием), приведи\n` +
        `вердикт release-readiness в соответствие с фактом (P4.1 🔴, P4.9/P4.10 не исполнялись).\n` +
        `Коммит: \`docs(plan): починка closure-матрицы по замечаниям верификатора (P5.3)\`.`,
      { label: 'repair:P5.3-matrix', phase: 'P5.3', model: 'opus', effort: 'high', schema: EXEC_SCHEMA },
    )
    if (repaired && !repaired.escalation) {
      closed.push('P5.3')
      log('✓ P5.3: матрица починена')
    } else {
      queue('P5.3', `матрица не сошлась: строк ${verify.rowsFound}/${verify.expected}, пропусков ${verify.missing.length}`, [])
    }
  } else {
    closed.push('P5.3')
    log(`✓ P5.3: матрица сошлась — ${verify.rowsFound}/${verify.expected}, вердикт честен`)
  }
}

log(`P5 завершена: закрыто ${closed.length}/3, человеку ${queued.length}`)

return {
  closed,
  queued,
  reviewFindings: { blockers: p51.blockers ?? null, majors: p51.majors ?? null },
  nextStep: queued.length
    ? 'P5 закрыта частично — разбери queued[]. Плюс всё ещё открыты P4.1 / P4.9 / P4.10 из MORNING-QUEUE.md.'
    : 'P5 закрыта целиком. Остаются P4.1 / P4.9 / P4.10 из MORNING-QUEUE.md — затем /task:plan-close archive VLT-REMED.',
}

export const meta = {
  name: 'vlt-remed-auto',
  description: 'Автономный догон VLT-REMED P1→P4: exec→review→auto-fix; несходящееся/неоднозначное — в morning-queue, БЕЗ остановки всего прогона. P5 (финальное ревью) НЕ трогает.',
  whenToUse: 'Overnight-догон VLT-REMED без человека: чинит только однозначное, спорное складывает на утро.',
  phases: [
    { title: 'P1', detail: 'W1 — быстрые высоко-эксплуатируемые' },
    { title: 'P2', detail: 'W2 — авторизация и изоляция' },
    { title: 'P3', detail: 'W3 — надстройки и интеграция' },
    { title: 'P4', detail: 'W4 — качество, DX, CI + канон структуры' },
  ],
}

// ─── Карта плана ────────────────────────────────────────────────────────────
// done[] и skeleton синхронизируются перед запуском из фактического статуса плана.
const PHASES = [
  { id: 'P1', items: 4, skeleton: false, done: ['P1.1', 'P1.2', 'P1.3', 'P1.4'] },
  { id: 'P2', items: 3, skeleton: false, done: ['P2.1', 'P2.2', 'P2.3'] },
  // P3.1–P3.5 закрыты чисто предыдущим прогоном; P3.6 закоммичен, но держит 1 Major
  // (connection-aware Schema в ALTER-миграции шифрования signing_secret) — драйвер до-чинит и re-review.
  { id: 'P3', items: 6, skeleton: false, done: ['P3.1', 'P3.2', 'P3.3', 'P3.4', 'P3.5'] },
  { id: 'P4', items: 10, skeleton: true, done: [] },
]

const ROUTING = {
  'P2.1': { model: 'opus', effort: 'high' },
  'P4.1': { model: 'sonnet', effort: 'medium' },
  'P4.8': { model: 'sonnet', effort: 'low' },
  'P4.9': { model: 'opus', effort: 'high' },
  'P4.10': { model: 'opus', effort: 'high' },
}
const routeFor = (item) => ROUTING[item] ?? { model: 'sonnet', effort: 'medium' }

// Риск-профиль: где ревью должно быть глубоким.
const DEEP_REVIEW = new Set(['P2.1', 'P4.9', 'P4.10'])

// Заведомо «вопросные» item'ы: высокий blast-radius / архитектурное решение —
// автономно ночью НЕ исполняем, сразу на утро под надзор человека.
const HUMAN_REQUIRED = new Set(['P4.9', 'P4.10'])

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
    filesChanged: { type: 'array', items: { type: 'string' } },
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

// ─── Промпты ────────────────────────────────────────────────────────────────
const COLD_START = `
Ты работаешь в репозитории /home/vostrikov/projects/packages/vaulter, ветка remediation/audit-vlt (D8).
SSOT плана: plans/VLT-REMED/plan.md, plans/VLT-REMED/handoff.md, plans/VLT-REMED/phases/<Pn>.md.
Нормы ремедиации (неизменны): plans/VLT-REMED/findings/P0-remediation-norms.md.
Реестр находок (append-only, НЕ править): plans/AUDIT-VLT/findings/REGISTER.md.

Жёсткие правила плана:
- Гейт валидации item'а из корня: composer lint:check && composer analyse && composer test && composer test:types.
  ВАЖНО (workaround из handoff P0.1): root phpstan/phpunit покрывают только packages/core.
  Если item трогает documents/drive/media/filament — гони и их собственные
  \`cd packages/<pkg> && composer analyse && composer test && composer test:types\`.
  Красный lint:check ТОЛЬКО на plans/AUDIT-VLT/artifacts/repro/** — известный пред-существующий
  долг вне scope, он closure не блокирует; свой новый код обязан быть pint-чистым.
- На каждый F-id — регрессионный тест, КРАСНЫЙ на коде до фикса, с F-id в описании (D7).
- Находка не воспроизводится → ЭСКАЛАЦИЯ (D9), не молчаливое закрытие.
- План разошёлся с кодом → НЕ импровизировать, эскалация на plan-design.
- Дифф вне Scope Included — не коммитить, записать в Pending Work.
`.trim()

const execPrompt = (item) => `
${COLD_START}

ЗАДАЧА: исполни item ${item} мастер-плана VLT-REMED по полному plan-протоколу (цикл 1–8),
ВКЛЮЧАЯ обязательное закрытие: коммит Conventional Commits (рус.) формата \`тип(scope): суть (${item})\`,
статус item'а → 🟢/🟠/🔴, Completion Notes/Pending Work/Known Deviations, синхронизация
\`## Phase Status\` и \`## 4. Phase Index & Status Board\`, строка в \`## 6. Update Log\`,
полная перезапись plans/VLT-REMED/handoff.md, прогон plan-lint.py.

Сделай это, вызвав скилл: Skill(skill="task:plan-exec", args="VLT-REMED ${item}").
Если item уже в терминальном статусе (🟢/🟠/⛔) — НЕ переисполняй, верни status по факту с commits=[].

Верни структурированный результат. escalation=true, если протокол выдал ESCALATION-REQUIRED
(находка не воспроизводится, план разошёлся с кодом, требуется решение человека).
`.trim()

const designPrompt = (phase) => `
${COLD_START}

ЗАДАЧА: разверни СКЕЛЕТ-фазу ${phase} мастер-плана VLT-REMED — детализируй все её items
(16 полей каждого) и закрой DoR-гейт. Блок «Обязательные оси» в Phase Context скелета —
вход развёртывания, а не пожелание.

Сделай это, вызвав скилл: Skill(skill="task:plan-design", args="VLT-REMED ${phase}").
Итог закоммить. Кода приложения НЕ пиши — это фаза проектирования.
`.trim()

const reviewPrompt = (item, deep) => `
Read-only adversarial-ревью диффа item'а ${item} плана VLT-REMED (ветка remediation/audit-vlt).
Дифф: \`git log --oneline -8\` найди коммиты этого item'а (subject содержит "(${item})"),
смотри \`git show\` по ним. Кода НЕ меняй.

Оси (в порядке приоритета):
1. «Зелёное, но не починенное» (pre-mortem R2/R3/R4): барьер стоит на пути, по которому реально
   идёт вред, — или на том, который смотрел аудитор? Регрессионный тест правда был бы красным
   на коде ДО фикса, или он проходит и там? Тест реально ГОНЯЕТСЯ в CI, а не осиротел?
2. Спец-код под тест: отклоняется ли КЛАСС дефекта, а не конкретная строка из теста.
3. Публичный контракт пакета / SemVer, ServiceProvider register-vs-boot, publishables.
4. Scope drift: изменено ли что-то вне Scope Included item'а.
5. Реальный ли Pending Work / Known Deviations, или там замели проблему под ковёр.
${deep ? '\nЭто ВЫСОКОРИСКОВЫЙ item: копай инварианты, а не чеклист. Конкурентные и обходные пути — явно.' : ''}

Гоняй проверки: vendor/bin/phpstan analyse, целевые pest-тесты. Верни только находки.
`.trim()

const autoFixPrompt = (item, findings) => `
${COLD_START}

Ревью item'а ${item} нашло дефекты. Почини те, что чинятся ОДНОЗНАЧНО и СТРОГО в рамках
Scope Included item'а ${item} (plans/VLT-REMED/phases/${item.slice(0, 2)}.md):

${findings.map((f, i) => `${i + 1}. [${f.severity}] ${f.file}${f.line ? ':' + f.line : ''} — ${f.summary}`).join('\n')}

ПРАВИЛА АВТОНОМНОГО РЕЖИМА (человек спит, спорное решаем не мы):
- Чини ТОЛЬКО механическое/однозначное: покрытие тестом, CI-гейт, узкий скоуп-фикс, docs/CHANGELOG.
- Если для устранения нужен АРХИТЕКТУРНЫЙ / SemVer-политический / дизайн-выбор, выход за Scope,
  или откат ранее принятого проектного решения — НЕ хакай тест, НЕ импровизируй, НЕ расширяй скоуп молча.
  Верни escalation=true с объяснением, что именно требует человека — это уйдёт в morning-queue.
- Фиксы — ОТДЕЛЬНЫМ коммитом (НЕ amend: трассируемость), формат \`fix(scope): суть по замечаниям ревью (${item})\`.
  Гейт валидации зелёный ДО коммита.
`.trim()

// ─── Прогон ─────────────────────────────────────────────────────────────────
const REVIEW_ROUNDS = 3 // impl → (review → fix) ×2 → review; несошлось → на утро
const results = []
const closed = []
const queued = [] // { item, reason, findings }

const queue = (item, reason, findings) => {
  queued.push({ item, reason, findings: findings ?? [] })
  log(`🟠 ${item}: отложено на утро — ${reason}`)
}

for (const ph of PHASES) {
  phase(ph.id)

  // Скелет-фазу нельзя исполнять напрямую — сперва развернуть.
  if (ph.skeleton) {
    log(`${ph.id}: скелет — разворачиваю через plan-design (opus/high)`)
    const design = await agent(designPrompt(ph.id), {
      label: `design:${ph.id}`,
      phase: ph.id,
      model: 'opus',
      effort: 'high',
      schema: DESIGN_SCHEMA,
    })
    if (!design || !design.dorClosed) {
      queue(ph.id, 'plan-design не закрыл DoR — фаза требует человека', [])
      continue // следующая фаза, НЕ стоп всего прогона
    }
    log(`${ph.id}: развёрнута, детализировано items: ${design.itemsDetailed}`)
  }

  for (let n = 1; n <= ph.items; n++) {
    const item = `${ph.id}.${n}`

    if (ph.done.includes(item)) {
      log(`${item}: уже 🟢 в плане — пропуск`)
      continue
    }

    if (HUMAN_REQUIRED.has(item)) {
      queue(item, 'высокий blast-radius (канон структуры / массовый namespace-refactor) — только под надзором человека', [])
      continue
    }

    const route = routeFor(item)
    log(`${item}: exec (${route.model}/${route.effort})`)

    let exec = await agent(execPrompt(item), {
      label: `exec:${item}`,
      phase: ph.id,
      model: route.model,
      effort: route.effort,
      schema: EXEC_SCHEMA,
    })
    if (!exec) {
      // одна повторная попытка на смерть агента
      exec = await agent(execPrompt(item), {
        label: `exec:${item}#retry`,
        phase: ph.id,
        model: route.model,
        effort: route.effort,
        schema: EXEC_SCHEMA,
      })
    }
    if (!exec) {
      queue(item, 'исполнитель умер дважды', [])
      continue
    }
    if (exec.escalation || exec.status === 'blocked') {
      queue(item, `эскалация исполнителя: ${exec.summary}`, [])
      continue
    }

    // ── Review-гейт: converge-or-queue. Чинит НЕ ревьюер (D11-симметрия).
    const deep = DEEP_REVIEW.has(item)
    let review = null
    let clean = false
    let lastHard = []

    for (let round = 1; round <= REVIEW_ROUNDS; round++) {
      review = await agent(reviewPrompt(item, deep), {
        label: `review:${item}${round > 1 ? `#${round}` : ''}`,
        phase: ph.id,
        agentType: 'vaulter-reviewer',
        model: deep ? 'opus' : 'sonnet',
        effort: deep ? 'high' : 'medium',
        schema: REVIEW_SCHEMA,
      })
      if (!review) break

      const hard = review.findings.filter((f) => f.severity === 'Blocker' || f.severity === 'Major')
      lastHard = hard

      if (hard.length === 0) {
        clean = true
        log(`✓ ${item}: ревью чистое (Minor/Nit: ${review.findings.length})`)
        break
      }
      if (round === REVIEW_ROUNDS) break

      log(`${item}: ${hard.length} Blocker/Major — авто-фикс раунд ${round}`)
      const fixed = await agent(autoFixPrompt(item, hard), {
        label: `fix:${item}#${round}`,
        phase: ph.id,
        model: route.model,
        effort: deep ? 'high' : route.effort,
        schema: EXEC_SCHEMA,
      })
      if (!fixed) break
      if (fixed.escalation) {
        // фикс упёрся в решение человека — не крутим впустую, на утро
        log(`${item}: авто-фикс эскалировал — на утро`)
        break
      }
    }

    if (clean) {
      closed.push(item)
      results.push({ item, status: exec.status, reviewClean: true, findings: review?.findings ?? [] })
    } else {
      queue(item, `ревью не сошлось после авто-фиксов (осталось ${lastHard.length} Blocker/Major)`, lastHard)
      results.push({ item, status: exec.status, reviewClean: false, findings: lastHard })
    }
    // В любом случае — следующий item. Прогон НЕ останавливается.
  }
}

log(`Автопрогон завершён: закрыто ${closed.length}, на утро ${queued.length}`)

return {
  closed,
  queued,
  results,
  nextStep: queued.length
    ? 'Разбери queued[] утром: неоднозначные/несошедшиеся items + отложенные high-blast-radius. Остальное закрыто автономно. Затем P5 (сквозной adversarial review) — вручную.'
    : 'Все P1–P4 закрыты автономно. P5 (сквозной adversarial review) — запускается человеком вручную.',
}

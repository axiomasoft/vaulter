export const meta = {
  name: 'vlt-remed-drive',
  description: 'Прогоняет мастер-план VLT-REMED от P0 до P4: design скелет-фаз, exec items по Routing, review-гейт на каждый item. P5 (финальное ревью) НЕ трогает.',
  whenToUse: 'Когда нужно довести VLT-REMED до конца автоматически, оставив финальный adversarial-review человеку.',
  phases: [
    { title: 'P0', detail: 'W0 — три Blocker’а (opus/high)' },
    { title: 'P1', detail: 'W1 — быстрые высоко-эксплуатируемые' },
    { title: 'P2', detail: 'W2 — авторизация и изоляция' },
    { title: 'P3', detail: 'W3 — надстройки и интеграция' },
    { title: 'P4', detail: 'W4 — качество, DX, CI + канон структуры' },
  ],
}

// ─── Карта плана ────────────────────────────────────────────────────────────
// Счётчики items — из plan.md §4 Phase Index & Status Board.
// skeleton: фаза ещё не развёрнута → перед exec нужен /task:plan-design (Execution Rules).
const PHASES = [
  { id: 'P0', items: 3, skeleton: false, done: ['P0.1', 'P0.2', 'P0.3'] },
  { id: 'P1', items: 4, skeleton: true, done: [] },
  { id: 'P2', items: 3, skeleton: true, done: [] },
  { id: 'P3', items: 6, skeleton: true, done: [] },
  { id: 'P4', items: 10, skeleton: true, done: [] },
]

// plan.md §3 Routing — отклонения от SSOT-матрицы скилла (дефолт sonnet/medium).
const ROUTING = {
  'P0.1': { model: 'opus', effort: 'high' },
  'P0.2': { model: 'opus', effort: 'high' },
  'P0.3': { model: 'opus', effort: 'high' },
  'P2.1': { model: 'opus', effort: 'high' },
  'P4.1': { model: 'sonnet', effort: 'medium' }, // + RAG на статусы CVE
  'P4.8': { model: 'sonnet', effort: 'low' },    // docs-drift, thinking вреден
  'P4.9': { model: 'opus', effort: 'high' },     // канон структуры + ADR
  'P4.10': { model: 'opus', effort: 'high' },    // массовый namespace-refactor
}
const routeFor = (item) => ROUTING[item] ?? { model: 'sonnet', effort: 'medium' }

// Риск-профиль: где ревью должно быть глубоким, а не формальным.
const DEEP_REVIEW = new Set(['P0.1', 'P0.2', 'P0.3', 'P2.1', 'P4.9', 'P4.10'])

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
Если item уже в терминальном статусе (🟢/🟠/⛔) — НЕ переисполняй, верни status по факту.

Верни структурированный результат. escalation=true, если протокол выдал ESCALATION-REQUIRED.
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
Дифф: \`git log --oneline -5\` найди коммиты этого item'а (subject содержит "(${item})"),
смотри \`git show\` по ним. Кода НЕ меняй.

Оси (в порядке приоритета):
1. «Зелёное, но не починенное» (pre-mortem R2/R3/R4): барьер стоит на пути, по которому реально
   идёт вред, — или на том, который смотрел аудитор? Регрессионный тест правда был бы красным
   на коде ДО фикса, или он проходит и там?
2. Спец-код под тест: отклоняется ли КЛАСС дефекта, а не конкретная строка из теста.
3. Публичный контракт пакета / SemVer, ServiceProvider register-vs-boot, publishables.
4. Scope drift: изменено ли что-то вне Scope Included item'а.
5. Реальный ли Pending Work / Known Deviations, или там замели проблему под ковёр.
${deep ? '\nЭто ВЫСОКОРИСКОВЫЙ item (Blocker/ядро авторизации/массовый рефактор): копай инварианты,\nа не чеклист. Проверь конкурентные и обходные пути явно.' : ''}

Гоняй проверки: vendor/bin/phpstan analyse, целевые pest-тесты. Верни только находки.
`.trim()

const fixPrompt = (item, findings) => `
${COLD_START}

Ревью item'а ${item} нашло дефекты, которые обязаны быть починены ДО перехода к следующему item'у:

${findings.map((f, i) => `${i + 1}. [${f.severity}] ${f.file}${f.line ? ':' + f.line : ''} — ${f.summary}`).join('\n')}

Почини их СТРОГО в рамках Scope Included item'а ${item} (plans/VLT-REMED/phases/${item.slice(0, 2)}.md).
Фиксы — ОТДЕЛЬНЫМ коммитом (НЕ amend: трассируемость — видно, что нашло ревью), формат
\`fix(scope): суть по замечаниям ревью (${item})\`. Гейт валидации зелёный ДО коммита.
Если находка требует выхода за Scope — не расширяй молча: запиши в Pending Work item'а и объясни.
`.trim()

// ─── Прогон ─────────────────────────────────────────────────────────────────
const REVIEW_ROUNDS = 2 // impl → review → fix → re-review; дальше circuit-breaker
const results = []
const halted = []

for (const ph of PHASES) {
  phase_loop: {
    phase(ph.id)

    // Скелет-фазу нельзя исполнять напрямую (Execution Rules) — сперва развернуть.
    if (ph.skeleton) {
      log(`${ph.id}: фаза — скелет, разворачиваю через plan-design (opus/high)`)
      const design = await agent(designPrompt(ph.id), {
        label: `design:${ph.id}`,
        phase: ph.id,
        model: 'opus',
        effort: 'high',
        schema: DESIGN_SCHEMA,
      })

      if (!design || !design.dorClosed) {
        halted.push(`${ph.id}: plan-design не закрыл DoR — фаза не исполняется`)
        log(`⛔ ${ph.id}: DoR не закрыт, останавливаю прогон`)
        break
      }
      log(`${ph.id}: развёрнута, детализировано items: ${design.itemsDetailed}`)
    }

    for (let n = 1; n <= ph.items; n++) {
      const item = `${ph.id}.${n}`

      if (ph.done.includes(item)) {
        log(`${item}: уже 🟢 в плане — пропускаю (терминальный item не переисполняется)`)
        continue
      }

      const route = routeFor(item)
      log(`${item}: exec (${route.model}/${route.effort})`)

      const exec = await agent(execPrompt(item), {
        label: `exec:${item}`,
        phase: ph.id,
        model: route.model,
        effort: route.effort,
        schema: EXEC_SCHEMA,
      })

      if (!exec) {
        halted.push(`${item}: агент-исполнитель умер / был прерван`)
        break phase_loop
      }

      if (exec.escalation || exec.status === 'blocked') {
        halted.push(`${item}: ESCALATION — ${exec.summary}`)
        log(`🔴 ${item}: эскалация, нужен /task:plan-design. Останавливаю прогон.`)
        break phase_loop
      }

      // ── Review-гейт: сколько бы ни было раундов, чинит НЕ ревьюер (D11-симметрия).
      const deep = DEEP_REVIEW.has(item)
      let review = null
      let clean = false

      for (let round = 1; round <= REVIEW_ROUNDS; round++) {
        review = await agent(reviewPrompt(item, deep), {
          label: `review:${item}${round > 1 ? `#${round}` : ''}`,
          phase: ph.id,
          agentType: 'vaulter-reviewer',
          model: deep ? 'opus' : 'sonnet',
          effort: deep ? 'high' : 'medium',
          schema: REVIEW_SCHEMA,
        })

        if (!review) {
          halted.push(`${item}: ревьюер умер`)
          break phase_loop
        }

        const hard = review.findings.filter((f) => f.severity === 'Blocker' || f.severity === 'Major')

        if (hard.length === 0) {
          clean = true
          log(`✓ ${item}: ревью чистое (Minor/Nit: ${review.findings.length})`)
          break
        }

        if (round === REVIEW_ROUNDS) {
          halted.push(`${item}: ревью держит ${hard.length} Blocker/Major после ${REVIEW_ROUNDS} раундов`)
          break
        }

        log(`${item}: ревью нашло ${hard.length} Blocker/Major — раунд фиксов ${round}`)
        const fixed = await agent(fixPrompt(item, hard), {
          label: `fix:${item}#${round}`,
          phase: ph.id,
          model: route.model,
          effort: deep ? 'high' : route.effort,
          schema: EXEC_SCHEMA,
        })

        if (!fixed || fixed.escalation) {
          halted.push(`${item}: фикс по ревью эскалировал — ${fixed?.summary ?? 'агент умер'}`)
          break phase_loop
        }
      }

      results.push({ item, status: exec.status, commits: exec.commits ?? [], reviewClean: clean, findings: review?.findings ?? [] })

      if (!clean) {
        log(`⛔ ${item}: circuit-breaker — ревью не сошлось. Останавливаю прогон.`)
        break phase_loop
      }
    }
  }

  if (halted.length) break // phase-first: фаза N+1 не стартует, пока N не терминальна
}

const done = results.filter((r) => r.status === 'done' || r.status === 'done_with_deviations')
log(`Итог: закрыто items ${done.length}/${results.length}; остановок: ${halted.length}`)

return {
  closed: done.map((r) => r.item),
  results,
  halted,
  nextStep: halted.length
    ? 'Прогон остановлен — разбери halted[], почини причину, перезапусти с resumeFromRunId.'
    : 'P0–P4 закрыты. Фаза P5 (сквозной adversarial code review) запускается ЧЕЛОВЕКОМ вручную — см. отчёт.',
}

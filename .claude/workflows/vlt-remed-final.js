export const meta = {
  name: 'vlt-remed-final',
  description: 'Закрывает остаточное автономное VLT-REMED: P4.1 Node-engine Major (hocuspocus v4 требует Node≥22) + независимая построчная сверка UPGRADE.md ↔ Known Deviations всех items (критерий 3) → пересчёт вердикта release-readiness. MSI и CHANGELOG-deny остаются человеку (ограничения окружения/пермишенов).',
  whenToUse: 'Финальная зачистка VLT-REMED после закрытия P4.1/P4.9/P4.10.',
  phases: [
    { title: 'P4.1-fix', detail: 'Node≥22 + drop Node20 CI-лейн + UPGRADE-нота (opus/high)' },
    { title: 'UPGRADE-audit', detail: 'построчная сверка Known Deviations ↔ UPGRADE.md (opus/high)' },
    { title: 'Matrix', detail: 'пересчёт closure-матрицы и вердикта' },
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

const AUDIT_SCHEMA = {
  type: 'object',
  required: ['gapsFound', 'gaps'],
  properties: {
    gapsFound: { type: 'integer' },
    gaps: {
      type: 'array',
      items: {
        type: 'object',
        required: ['item', 'bc', 'why'],
        properties: {
          item: { type: 'string' },
          bc: { type: 'string' },
          why: { type: 'string' },
        },
      },
    },
    notes: { type: 'string' },
  },
}

const MATRIX_SCHEMA = {
  type: 'object',
  required: ['rowsFound', 'expected', 'missing', 'verdict', 'verdictHonest', 'openCriteria'],
  properties: {
    rowsFound: { type: 'integer' },
    expected: { type: 'integer' },
    missing: { type: 'array', items: { type: 'string' } },
    verdict: { type: 'string' },
    verdictHonest: { type: 'boolean' },
    openCriteria: { type: 'array', items: { type: 'string' } },
    notes: { type: 'string' },
  },
}

const COLD_START = `
Репозиторий /home/vostrikov/projects/packages/vaulter, ветка remediation/audit-vlt.
План: plans/VLT-REMED/. Матрица: plans/VLT-REMED/findings/P5.3-closure-matrix.md.
Реестр (append-only, НЕ править): plans/AUDIT-VLT/findings/REGISTER.md (103).

СОСТОЯНИЕ: закрыты P0–P3, P4.2–P4.10, вся P5. Вердикт release-readiness = НЕ ГОТОВ по трём осям:
крит.5 MSI (провизорный порог — Infection не запускается в песочнице, вне автономного контроля),
крит.3 UPGRADE неполнота (сверялась грепом по F-id), и новый Major по P4.1 (Node-engine).

Актуальность версий — только RAG на момент исполнения (context7/perplexity), не из памяти.
Гейт из корня: composer lint:check && composer analyse && composer test && composer test:types;
для hocuspocus-server: \`cd hocuspocus-server && npm test && npm run check\`.
Красный lint:check ТОЛЬКО на plans/AUDIT-VLT/artifacts/repro/** — пред-существующий долг вне scope.
Тип коммита ЛАТИНИЦЕЙ, subject — реальное описание.
`.trim()

const p41FixPrompt = `
${COLD_START}

Закрой открытый Major по P4.1 (единственный оставшийся). Read the finding:
@hocuspocus/server@4.3.0 декларирует engines.node >=22 (подтверждено в package-lock.json), НО
hocuspocus-server/package.json декларирует engines.node >=20, и CI-лейн hocuspocus гоняет Node 20
(frontend.yml matrix). Под engine-strict npm ci упадёт; Node-20 лейн тестирует конфиг, который
прямая зависимость больше не поддерживает — «поддержка Node 20» стала фиктивной.

Владелец требует АКТУАЛЬНЫЕ версии — значит выравниваем support-range вверх, честно:
1. hocuspocus-server/package.json: engines.node → ">=22" (сверь RAG-ом минимум, требуемый
   @hocuspocus/server@latest; зафиксируй в Completion Notes). Проверь остальные @hocuspocus/* и yjs
   на согласованность engine-требований.
2. Убери Node 20 из hocuspocus-матрицы в .github/workflows/frontend.yml (или где лейн объявлен —
   grep 'hocuspocus' по .github/workflows). Оставь Node 22 (и выше, если актуально).
3. UPGRADE.md: запись — collab-сервер теперь требует Node >=22 (следствие hocuspocus v4).
   Это BC для потребителя на Node 20 → пометь major/breaking.
4. Гейт: hocuspocus-server npm test + check зелёные на Node 22; PHP-гейт зелёный.
5. Обнови статус P4.1 (должен остаться 🟢), Completion Notes, closure-матрицу (строка F-P4.5-02 —
   уточни support-range), plan-lint.py. Коммит:
   \`fix(collab): поднять минимум Node до 22 под hocuspocus v4 + выровнять CI-лейн (P4.1)\`.
escalation=true только если актуальный hocuspocus требует нового решения владельца. Верни результат.
`.trim()

const reviewP41 = `
Read-only adversarial-ревью фикса Node-engine по P4.1 (ветка remediation/audit-vlt, коммит с "(P4.1)"
про Node/engines). \`git show\`. Кода НЕ меняй.
Оси: (1) engines.node и CI-матрица hocuspocus согласованы с реальным требованием
@hocuspocus/server@latest (сверь package-lock)? (2) Node 20 убран ИЗ ВСЕХ мест, где он тестировал
hocuspocus (не остался в другом лейне)? (3) BC про Node≥22 в UPGRADE.md есть? (4) npm test/check
зелёные, backend-швы целы? Верни только находки.
`.trim()

const auditPrompt = `
${COLD_START}

НЕЗАВИСИМАЯ построчная сверка (критерий 3 матрицы — «UPGRADE.md покрывает ВСЕ breaking»). Read-only,
ничего не чини и не коммить. Механизм «grep по F-id» структурно НЕ ловит BC без собственного F-id
(так дважды терялся EnumCaseAttributeResolver static→instance).

Пройди Known Deviations / Completion Notes / Pending Work ВСЕХ items во всех plans/VLT-REMED/phases/P*.md
+ находки P5.2 (R-01…R-11). Для КАЖДОГО потенциально ломающего изменения (смена сигнатуры public-метода,
static↔instance, перенос/переименование класса/namespace — включая P4.10, изменение дефолта конфига,
удаление/переименование публичного контракта, смена минимальных версий) проверь: есть ли соответствующая
запись в UPGRADE.md? Сверяй по СУТИ (класс/метод/поведение), НЕ по F-id.
Верни gaps[] = BC-изменения без записи в UPGRADE.md (item, что за BC, почему это BC). gapsFound=0 —
если UPGRADE.md полон.
`.trim()

const auditFixPrompt = (gaps) => `
${COLD_START}

Независимая сверка нашла ломающие изменения БЕЗ записи в UPGRADE.md:

${gaps.map((g, i) => `${i + 1}. [${g.item}] ${g.bc} — почему BC: ${g.why}`).join('\n')}

Допиши недостающие секции в UPGRADE.md (по конвенции соседних записей: что изменилось, кого касается,
как мигрировать, major/breaking). CHANGELOG.md НЕ трогай (заблокирован deny-правилом харнесса).
Гейт lint:check зелёный (меняешь только .md). Коммит:
\`docs(upgrade): дописать недостающие BC-секции по независимой сверке (P5.3)\`.
Верни результат.
`.trim()

const matrixPrompt = `
${COLD_START}

ПЕРЕСЧИТАЙ plans/VLT-REMED/findings/P5.3-closure-matrix.md после закрытия Node-engine Major (P4.1) и
дополнения UPGRADE.md. Обнови статусы и КРИТЕРИИ release-readiness по фактам:
- крит.3 (UPGRADE полнота): после независимой построчной сверки — ✅ если пропусков не осталось,
  иначе честно ⚠️/❌ со списком.
- крит.5 (MSI): остаётся ❌ — Infection не запускается в песочнице (нет xdebug/pcov). Это ЧЕСТНОЕ
  ограничение окружения, НЕ выдавай за ✅. Явно отметь как «требует прогона в реальном окружении / или
  осознанного принятия advisory-MSI владельцем».
- Пересчитай итоговый вердикт по правилу «любой не-✅ → НЕ ГОТОВ». Перечисли ОСТАВШИЕСЯ открытые
  критерии (openCriteria).
Закрой по plan-протоколу: Status Board / Update Log / handoff.md, plan-lint.py. Коммит:
\`docs(plan): пересчёт вердикта release-readiness после Node-engine + UPGRADE-аудита (P5.3)\`.
`.trim()

const matrixVerify = `
${COLD_START}

МАШИННЫЙ ВЕРИФИКАТОР матрицы (plans/VLT-REMED/findings/P5.3-closure-matrix.md). Read-only.
1. rowsFound vs expected (F-id в REGISTER.md, ждём 103) — посчитай оба грепом.
2. missing[] — пропущенные F-id и «fake-closed: <F-id>» (статус «закрыт» без живого коммита
   \`git cat-file -e\` / без живого теста grep'ом).
3. verdict — процитируй итоговый вердикт. openCriteria[] — какие критерии release-readiness ещё не ✅.
4. verdictHonest=false, если матрица рисует «готов» при живом открытом критерии, ИЛИ ставит крит.5 (MSI)
   как ✅ (MSI не измерялся — должно быть ❌ до реального прогона).
`.trim()

// ─── Прогон ─────────────────────────────────────────────────────────────────
const ROUNDS = 2
const closed = []
const queued = []
const queue = (item, reason, findings) => {
  queued.push({ item, reason, findings: findings ?? [] })
  log(`🟠 ${item}: человеку — ${reason}`)
}

// ── P4.1 Node-engine fix + review ───────────────────────────────────────────
phase('P4.1-fix')
log('P4.1: Node≥22 + drop Node20 CI-лейн + UPGRADE-нота (opus/high)')
const fix = await agent(p41FixPrompt, { label: 'fix:P4.1-node', phase: 'P4.1-fix', model: 'opus', effort: 'high', schema: EXEC_SCHEMA })
if (!fix || fix.status === 'blocked') {
  queue('P4.1', `Node-engine фикс не сделан: ${fix?.summary ?? 'агент умер'}`, [])
} else if (fix.escalation) {
  queue('P4.1', `эскалация: ${fix.summary}`, [])
} else {
  let clean = false
  let lastHard = []
  for (let round = 1; round <= ROUNDS; round++) {
    const review = await agent(reviewP41, {
      label: `review:P4.1${round > 1 ? `#${round}` : ''}`,
      phase: 'P4.1-fix',
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
      log(`✓ P4.1: Node-engine ревью чистое (Minor/Nit: ${review.findings.length})`)
      break
    }
    if (round === ROUNDS) break
    log(`P4.1: ${hard.length} Blocker/Major — авто-фикс`)
    const refix = await agent(auditFixPromptOrExec(hard), {
      label: `fix:P4.1#${round}`,
      phase: 'P4.1-fix',
      model: 'opus',
      effort: 'high',
      schema: EXEC_SCHEMA,
    })
    if (!refix || refix.escalation) break
  }
  if (clean) closed.push('P4.1')
  else queue('P4.1', `Node-engine ревью не сошлось (осталось ${lastHard.length} Blocker/Major)`, lastHard)
}

function auditFixPromptOrExec(hard) {
  return `${COLD_START}\n\nРевью Node-engine фикса P4.1 держит дефекты:\n` +
    hard.map((f, i) => `${i + 1}. [${f.severity}] ${f.file}${f.line ? ':' + f.line : ''} — ${f.summary}`).join('\n') +
    `\n\nПочини строго в рамках Scope (Node-engine/CI/UPGRADE). Отдельный коммит, тип латиницей, гейт зелёный.\n` +
    `escalation=true только при упоре в новое решение владельца.`
}

// ── UPGRADE-аудит: независимая построчная сверка ─────────────────────────────
phase('UPGRADE-audit')
log('UPGRADE-audit: независимая сверка Known Deviations ↔ UPGRADE.md (крит.3)')
const audit = await agent(auditPrompt, { label: 'audit:upgrade', phase: 'UPGRADE-audit', model: 'opus', effort: 'high', schema: AUDIT_SCHEMA })
if (!audit) {
  queue('UPGRADE-audit', 'аудитор умер — крит.3 не подтверждён', [])
} else if (audit.gapsFound > 0 && audit.gaps.length) {
  log(`UPGRADE-audit: ${audit.gapsFound} пропусков — дописываю UPGRADE.md`)
  const fixed = await agent(auditFixPrompt(audit.gaps), { label: 'fix:upgrade-gaps', phase: 'UPGRADE-audit', model: 'opus', effort: 'high', schema: EXEC_SCHEMA })
  if (!fixed || fixed.escalation) queue('UPGRADE-audit', `пропуски UPGRADE не закрыты: ${fixed?.summary ?? 'агент умер'}`, [])
  else {
    closed.push('UPGRADE-audit')
    log('✓ UPGRADE-audit: пропуски дописаны')
  }
} else {
  closed.push('UPGRADE-audit')
  log('✓ UPGRADE-audit: пропусков нет, UPGRADE.md полон')
}

// ── Matrix: пересчёт вердикта ────────────────────────────────────────────────
phase('Matrix')
log('Matrix: пересчёт closure-матрицы и вердикта release-readiness')
const matrix = await agent(matrixPrompt, { label: 'exec:P5.3-recalc', phase: 'Matrix', model: 'sonnet', effort: 'medium', schema: EXEC_SCHEMA })
let verdict = null
let openCriteria = []
if (!matrix || matrix.escalation || matrix.status === 'blocked') {
  queue('P5.3-matrix', `матрица не пересчитана: ${matrix?.summary ?? 'агент умер'}`, [])
} else {
  const verify = await agent(matrixVerify, { label: 'verify:P5.3', phase: 'Matrix', model: 'opus', effort: 'high', schema: MATRIX_SCHEMA })
  if (!verify) {
    queue('P5.3-matrix', 'верификатор матрицы умер', [])
  } else {
    verdict = verify.verdict
    openCriteria = verify.openCriteria ?? []
    if (verify.missing.length || verify.rowsFound !== verify.expected || !verify.verdictHonest) {
      queue('P5.3-matrix', `матрица не сошлась: строк ${verify.rowsFound}/${verify.expected}, дефектов ${verify.missing.length}, честен: ${verify.verdictHonest}`, [])
    } else {
      closed.push('P5.3-matrix')
      log(`✓ Matrix: ${verify.rowsFound}/${verify.expected}; вердикт: ${verify.verdict}; открытых критериев: ${openCriteria.length}`)
    }
  }
}

log(`Финал: закрыто ${closed.length} (${closed.join(', ')}); человеку ${queued.length}`)

return {
  closed,
  queued,
  verdict,
  openCriteria,
  humanResiduals: [
    'Критерий 5 (MSI): Infection не запускается в песочнице (нет xdebug/pcov) — нужен реальный прогон и калибровка порога, ЛИБО осознанное принятие advisory-MSI.',
    'CHANGELOG.md: deny-правило в .claude/settings.local.json — снять может только владелец, затем консолидировать BC из UPGRADE.md.',
    'Финальная архивация: /task:plan-close archive VLT-REMED — по замыслу требует approve владельца.',
  ],
  nextStep: queued.length
    ? 'Разбери queued[]. Остаточные человеку — humanResiduals. Вердикт: ' + (verdict ?? '—')
    : 'Автономное закрыто. Вердикт: ' + (verdict ?? '—') + '. Остаток — humanResiduals (MSI/CHANGELOG/archive).',
}

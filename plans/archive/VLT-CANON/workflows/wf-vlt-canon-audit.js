// audit-adversarial (шаблон 3) под VLT-CANON: DoR-гейт design-фазы над plan.md + findings.
// Read-only: ни один агент ничего не правит и не коммитит.
export const meta = {
  name: 'vlt-canon-audit',
  description: 'Adversarial DoR-аудит плана VLT-CANON: 4 finder-измерения, dedup, независимая верификация',
  whenToUse: 'DoR-гейт design-фазы VLT-CANON перед handoff в /task:dev',
  phases: [
    { title: 'Find', detail: 'по finder-агенту на измерение, параллельно', model: 'sonnet' },
    { title: 'Dedup', detail: 'слияние дублей, нормализация severity', model: 'sonnet' },
    { title: 'Verify', detail: 'адверсариальная проверка каждой находки свежим контекстом', model: 'opus' },
  ],
}

// ── Конфиг (embedded) ────────────────────────────────────────────────────────
const TARGET = {
  paths: [
    '/home/vostrikov/projects/packages/vaulter/plans/VLT-CANON/plan.md',
    '/home/vostrikov/projects/packages/vaulter/plans/VLT-CANON/findings/structure-canon-inventory.md',
  ],
  readHint: 'прочитай plan.md и инвентарь целиком; findings/*-research.md и код репо — точечно по ссылкам из плана',
}

const CONSTITUTION = [
  '/home/vostrikov/projects/packages/vaulter/plans/VLT-HSEAM/plan.md',
  '/home/vostrikov/projects/packages/vaulter/plans/VLT-HSEAM/root/adr-file-handler-seam.md',
  '/home/vostrikov/projects/packages/vaulter/docs/adr/0017-package-structure-canon.md',
  '/home/vostrikov/projects/packages/vaulter/docs/plan/p2-5-option-b-topology.md',
]

const DIMENSIONS = [
  {
    key: 'code-groundedness',
    brief: [
      'Сверь утверждения плана с РЕАЛЬНЫМ кодом репо /home/vostrikov/projects/packages/vaulter:',
      'существуют ли названные файлы/классы (core/src/Contracts/Documents/*, core/src/ValueObjects/Documents/*,',
      'documents/src/Documents/*, drive/src/Uploads/*, packages/vue/src/components/VaulterDocumentNode.vue,',
      'core/src/Attributes/TiptapExtensions.php, PanelTablesBuilder::defineDocumentVersions default tiptap),',
      'верны ли пути переносов P0.1–P0.5/P2.1–P2.4, не пропущены ли использования, которые перенос сломает',
      '(включая packages/vue/src/types.ts и hocuspocus-server). Находка = конкретное расхождение план↔код.',
      'НЕ находка: стилистика плана, желание больше деталей там, где план явно откладывает решение на фазу.',
    ].join(' '),
  },
  {
    key: 'plan-structure-dor',
    brief: [
      'Структурная полнота исполняемого мастер-плана (plan-protocol): Meta-таблица, Execution Rules,',
      'Routing, Status Board соответствует фазам, Decision Log непротиворечив (D7/D9/D10/D11 открыты — у',
      'каждого есть резолв-точка в конкретном item и блок в §8; D8/D12 закрыты — не противоречат ли items),',
      'каждый item несёт приёмку, зависимость от VLT-REMED/ACTIVE оговорена, supersede VLT-HSEAM оформлен',
      'консистентно (не остаётся ли двойного владения поверхностью). Находка = отсутствующий/противоречивый',
      'элемент схемы с конкретным местом. НЕ находка: вкусовые пожелания к формату.',
    ].join(' '),
  },
  {
    key: 'inheritance-consistency',
    brief: [
      'Непротиворечивость наследования: план поглощает VLT-HSEAM (D1–D8 того плана) и опирается на',
      'ADR-0017/topology Option B. Сверь: не переворачивает ли VLT-CANON молча решения-предшественники',
      '(bespoke-контракт vs Manager, динамический резолв, schema-owner core, layer-canon A), все ли 5 утечек',
      'L1–L5 VLT-HSEAM покрыты items P0.1–P0.5, не потерян ли DX-каркас/capability-policy, корректно ли',
      'перенесены Q1/Q2 → D10/D11. Находка = молчаливый переворот или потерянный элемент предшественника.',
    ].join(' '),
  },
  {
    key: 'research-groundedness',
    brief: [
      'RAG-groundedness: сверь решения D8/D12 и research-довесы P0.5/P1.2 с фактическим содержимым',
      'findings/{frontend-engine-packaging,drive-editor-resolution,editor-driver-isolation}-research.md —',
      'не приписывает ли план research\'у то, чего там нет; учтены ли REFUTED-вердикты (Statamic API-имя,',
      'неуниверсальность server-резолва, дата Hocuspocus, ложная атрибуция 4-сущностного контракта);',
      'не построено ли решение на REFUTED/UNVERIFIED утверждении без пометки. Находка = решение плана,',
      'опирающееся на опровергнутое/несуществующее утверждение research.',
    ].join(' '),
  },
]

const SEVERITY = ['Blocker', 'Major', 'Minor', 'Nit']
const JUDGE_PANEL = false
const FINDER_MODEL = { model: 'sonnet', effort: 'medium' }
const DEDUP_MODEL = { model: 'sonnet', effort: 'low' }
const VERIFY_MODEL = { model: 'opus', effort: 'high' }

// ── Схемы structured-summary ─────────────────────────────────────────────────
const FINDING = {
  type: 'object',
  properties: {
    dimension: { type: 'string' },
    file: { type: 'string' },
    line: { type: 'integer' },
    summary: { type: 'string', description: 'Дефект одним предложением' },
    failure_scenario: { type: 'string', description: 'Конкретный вход/состояние → неверный результат' },
    severity: { type: 'string', enum: SEVERITY },
    rule_ref: { type: 'string', description: 'Ссылка конституция/скилл → правило (для conformance-находок)' },
  },
  required: ['dimension', 'file', 'summary', 'failure_scenario', 'severity'],
}

const FINDINGS_SCHEMA = {
  type: 'object',
  properties: { findings: { type: 'array', items: FINDING } },
  required: ['findings'],
}

const VERDICTS_SCHEMA = {
  type: 'object',
  properties: {
    verdicts: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          summary: { type: 'string', description: 'summary находки — ключ соответствия' },
          verdict: { type: 'string', enum: ['CONFIRMED', 'REJECTED', 'PLAUSIBLE'] },
          note: { type: 'string' },
          severity: { type: 'string', enum: SEVERITY, description: 'скорректированная severity' },
        },
        required: ['summary', 'verdict', 'note'],
      },
    },
  },
  required: ['verdicts'],
}

// ── Промпты ──────────────────────────────────────────────────────────────────
function finderPrompt(d) {
  return [
    `Finder-аудит по измерению «${d.key}». Цель: ${TARGET.paths.join(', ')} (${TARGET.readHint}).`,
    `Конституция соответствия — прочитай прежде чем искать: ${CONSTITUTION.join(', ')}.`,
    `Измерение: ${d.brief}`,
    `READ-ONLY: ничего не правь и не коммить. Каждая находка — с file/line и конкретным failure_scenario; «может быть плохо» без сценария = не находка. Severity строго из ${JSON.stringify(SEVERITY)}. Пустой массив findings = чисто по измерению, это валидный результат.`,
    `Верни строго по схеме. Финальный текст = данные, не сообщение человеку.`,
  ].join('\n')
}

function dedupPrompt(all) {
  return [
    `Дедупликация находок аудита (${all.length} шт. от ${DIMENSIONS.length} finder'ов). НЕ ищи новые находки, НЕ читай цель — работай только со списком.`,
    `Правила: одинаковый дефект из разных измерений = одна находка (сохрани все dimension через запятую, возьми максимальную severity); соседние симптомы одного корня = одна находка с общим failure_scenario.`,
    `Находки:\n${JSON.stringify(all, null, 2)}`,
    `Верни строго по схеме (тот же формат findings).`,
  ].join('\n')
}

function verifyPrompt(findings, judgeIdx) {
  return [
    `Адверсариальная верификация находок аудита свежим контекстом${judgeIdx != null ? ` (независимый судья #${judgeIdx + 1} — не знаешь мнений других)` : ''}. Цель: ${TARGET.paths.join(', ')}.`,
    `Твоя задача — ОТКЛОНИТЬ каждую находку: перечитай указанное место сам и попробуй доказать, что дефекта нет. CONFIRMED только когда сам воспроизвёл failure_scenario по коду/тексту; не смог ни подтвердить, ни отклонить = PLAUSIBLE; находка ошибочна = REJECTED с объяснением в note. Можно скорректировать severity.`,
    `READ-ONLY: ничего не правь. Находки:\n${JSON.stringify(findings, null, 2)}`,
    `Верни строго по схеме.`,
  ].join('\n')
}

// ── Оркестрация ──────────────────────────────────────────────────────────────
log(`vlt-canon-audit: ${DIMENSIONS.length} измерений, judge-panel=${JUDGE_PANEL}`)

const found = await parallel(DIMENSIONS.map(d =>
  () => agent(finderPrompt(d), {
    label: `find:${d.key}`, phase: 'Find',
    ...FINDER_MODEL, schema: FINDINGS_SCHEMA,
  })
))

const raw = found.filter(Boolean).flatMap(r => r.findings || [])
if (!raw.length) { log('находок нет — аудит чист'); return { findings: [], verdicts: [] } }

const deduped = DIMENSIONS.length > 1 && raw.length > 1
  ? (await agent(dedupPrompt(raw), { label: 'dedup', phase: 'Dedup', ...DEDUP_MODEL, schema: FINDINGS_SCHEMA }))?.findings || raw
  : raw

log(`находок: ${raw.length} → после dedup: ${deduped.length}`)

let verdicts
if (JUDGE_PANEL) {
  const panel = await parallel([0, 1, 2].map(i =>
    () => agent(verifyPrompt(deduped, i), {
      label: `judge:${i + 1}`, phase: 'Verify',
      ...VERIFY_MODEL, schema: VERDICTS_SCHEMA,
    })
  ))
  const votes = panel.filter(Boolean).map(p => p.verdicts)
  verdicts = deduped.map(f => {
    const v = votes.map(vs => vs.find(x => x.summary === f.summary)).filter(Boolean)
    const count = t => v.filter(x => x.verdict === t).length
    const verdict = count('CONFIRMED') >= 2 ? 'CONFIRMED' : count('REJECTED') >= 2 ? 'REJECTED' : 'PLAUSIBLE'
    return { summary: f.summary, verdict, note: v.map(x => x.note).join(' | '), severity: f.severity }
  })
} else {
  const v = await agent(verifyPrompt(deduped, null), {
    label: 'verify', phase: 'Verify',
    ...VERIFY_MODEL, schema: VERDICTS_SCHEMA,
  })
  verdicts = (v && v.verdicts) || []
}

const confirmed = deduped
  .map(f => ({ ...f, ...(verdicts.find(v => v.summary === f.summary) || { verdict: 'PLAUSIBLE' }) }))
  .filter(f => f.verdict !== 'REJECTED')
  .sort((a, b) => SEVERITY.indexOf(a.severity) - SEVERITY.indexOf(b.severity))

log(`итог: ${confirmed.length} находок пережили верификацию (из ${deduped.length})`)
return { findings: confirmed, rejected: deduped.length - confirmed.length }

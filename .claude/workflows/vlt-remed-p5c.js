export const meta = {
  name: 'vlt-remed-p5c',
  description: 'Добивание двух XSS-находок P5 (codeBlock-фенс + URL-атрибуты конфиг-шва), adversarial-верификация, обновление closure-матрицы.',
  whenToUse: 'Финальный догон P5 VLT-REMED: 1 Blocker + 1 Major того же XSS-класса.',
  phases: [
    { title: 'Fix', detail: 'обе находки + red-before тесты (opus/high)' },
    { title: 'Verify', detail: 'adversarial-верификация исполнением (opus/xhigh)' },
    { title: 'Matrix', detail: 'обновление closure-матрицы P5.3' },
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

const COLD_START = `
Репозиторий /home/vostrikov/projects/packages/vaulter, ветка remediation/audit-vlt.
План: plans/VLT-REMED/. Находки сквозного ревью: plans/VLT-REMED/findings/P5.1-review-findings.md.

Гейт из корня: composer lint:check && composer analyse && composer test && composer test:types.
Затронуты documents/core/drive — гони и их: \`cd packages/<pkg> && composer analyse && composer test && composer test:types\`.
Красный lint:check ТОЛЬКО на plans/AUDIT-VLT/artifacts/repro/** — пред-существующий долг вне scope.
Тип коммита ЛАТИНИЦЕЙ (fix/test/docs), subject — РЕАЛЬНОЕ описание, не шаблон «суть по замечаниям».

⚑ КОНТРАКТ TipTap (решение владельца, данность — не переобсуждать):
allow-list синхронизирован с #[TiptapExtensions]; вынесен в конфиг (шов расширения хостом);
на чтении decay/strip, а не throw; **расширение allow-list НЕ отменяет санитизацию** — XSS-барьер
обязан покрывать и то, что хост добавил через шов.
`.trim()

const fixPrompt = `
${COLD_START}

Две находки одного класса — «отклонили строку из теста, а не КЛАСС дефекта». Обе ДОКАЗАНЫ ИСПОЛНЕНИЕМ.
Почини обе. Каждая — с регрессионным тестом, КРАСНЫМ на коде ДО фикса, id находки в описании теста.

── 1. [Blocker] R-04 не закрыт: packages/documents/src/Support/TipTapRenderer.php:185
Markdown-ветка узла codeBlock интерполирует СЫРЫМИ и attrs.language, и plainText(children) между фенсами.
Store-time валидатор проверяет только КЛЮЧИ атрибутов, а текст пропускает как легитимный → оба вектора
принимаются на записи. Живые вектора (воспроизведены на рендерере):
  (а) текст codeBlock со строкой \`\`\` закрывает фенс → в экспортированный .md выходит ЖИВОЙ
      <script>alert(1)</script>;
  (б) attrs.language с переводами строк выпускает ЖИВУЮ ссылку [pwn](javascript:alert(1)).
Боевой путь: MarkdownExporter::export() -> TipTapRenderer::render(Markdown).
Ирония: ТОТ ЖЕ коммит (ff93042) починил это для инлайн-марки code в markdownCodeSpan(), а узел
codeBlock оставил сырым.
ЧИНИТЬ КЛАСС: нейтрализовать содержимое фенса (вырезать/экранировать строки-фенсы либо перейти на
indent-4 для опасного содержимого) И валидировать language по строгому \`[A-Za-z0-9_+-]*\`.
Пройди ВЕСЬ TipTapRenderer: нет ли ещё узлов/марок с сырой интерполяцией в markdown-ветке
(это класс — ищи все места, а не только codeBlock).

── 2. [Major] Конфиг-шов заново открыл F-P1.11-01: packages/core/src/ValueObjects/Documents/TipTapContent.php:100
URL_ATTRIBUTES — хардкодный private const (link.href, image.src). Но vaulter.tiptap.extra_nodes +
extra_attributes позволяют хосту объявить СВОИ URL-несущие атрибуты, и для них НЕТ ни store-time
проверки схемы (validateAttributes:340), НИ read-path скраба (scrubUrlAttribute:507) — а
NodeDocumentVersionResource::readableContent() отдаёт результат прямо в редактор.
Доказано исполнением: extra_nodes=['iframe'] / extra_attributes=['iframe'=>['src']] →
{"type":"iframe","attrs":{"src":"javascript:alert(1)"}} ПРИНИМАЕТСЯ на записи и ПЕРЕЖИВАЕТ
withSanitizedUrls(), тогда как канонический image.src корректно ОТВЕРГАЕТСЯ.
Докблок класса (:33-35) и config/vaulter.php утверждают, что проверка схемы — «security invariant,
not a matter of host taste»: для двух каноничных атрибутов это правда, для хостовых — молча ложь.
ЧИНИТЬ КЛАСС (по ⚑ п.«расширение НЕ отменяет санитизацию»): сделать карту URL-атрибутов частью шва
(vaulter.tiptap.url_attributes) и прогонять их через LinkSchemeValidator И в validateAttributes(),
И в scrubUrlAttribute(). Либо (запасной вариант) отвергать extra_attributes с именами
src/href/url/poster, не зарегистрированные как URL-несущие. Fail-closed, не fail-open.

── Требования
- Тесты обязаны ГОНЯТЬСЯ в CI (не осиротеть в неподключённом лейне/директории).
- Никакого спец-кода под тест: отклоняется КЛАСС, а не конкретная строка.
- Гейт зелёный ДО коммита. Обнови Completion Notes/Known Deviations P5.2 в plans/VLT-REMED/phases/P5.md.
- Коммиты раздельные, с внятным subject:
  \`fix(documents): нейтрализация фенса и language в markdown-ветке codeBlock (P5.2, R-04)\`
  \`fix(core): URL-атрибуты конфиг-шва проходят проверку схемы на записи и чтении (P5.2, R-11)\`
escalation=true — только если упрёшься в решение, которого нет в ⚑.
`.trim()

const verifyPrompt = `
${COLD_START}

Read-only adversarial-ВЕРИФИКАЦИЯ двух только что сделанных фиксов (ветка remediation/audit-vlt,
коммиты с "(P5.2, R-04)" и "(P5.2, R-11)"). Кода НЕ меняй.

Не верь описанию — ПРОВЕРЬ ИСПОЛНЕНИЕМ:
1. codeBlock/markdown: сам построй TipTap-документ с (а) текстом, содержащим \`\`\` и <script>,
   (б) attrs.language с переводом строки и javascript:-ссылкой. Прогони MarkdownExporter::export().
   В выходном .md остаётся ЖИВОЙ script/ссылка? Значит класс НЕ закрыт.
   Проверь и ДРУГИЕ узлы/марки markdown-ветки TipTapRenderer на сырую интерполяцию — фикс мог
   закрыть только codeBlock и снова оставить класс открытым.
2. Конфиг-шов: объяви extra_nodes/extra_attributes с URL-несущим атрибутом (iframe.src, poster, свой
   кастомный) и попробуй пронести javascript:/data:/vbscript:. Проходит на записи? Переживает
   withSanitizedUrls()? Значит класс НЕ закрыт. Проверь ОБА пути — validateAttributes И scrubUrlAttribute.
3. Регресс-тесты этих фиксов действительно КРАСНЫЕ на коде ДО фикса (git stash/revert — проверь),
   и они реально ГОНЯЮТСЯ в CI-лейне, а не осиротели.
4. Фикс не сломал легитимный контент: канонические image.src/link.href с http(s) по-прежнему проходят;
   обычный codeBlock с кодом экспортируется корректно.
5. Не появилось ли fail-open: неизвестный/незарегистрированный атрибут — отвергается, а не пропускается?

Верни только находки, каждая с file:line и доказательством ИСПОЛНЕНИЕМ.
`.trim()

const matrixPrompt = `
${COLD_START}

ЗАДАЧА: обнови plans/VLT-REMED/findings/P5.3-closure-matrix.md после закрытия двух последних находок
(R-04 codeBlock-фенс, R-11 URL-атрибуты конфиг-шва) — матрица строилась ДО них и устарела.

- Добавь/обнови строки по находкам сквозного ревью P5.1 (R-01…R-10) + новую R-11.
- Каждая строка «закрыт» обязана ссылаться на ЖИВОЙ коммит и ЖИВОЙ тест — проверь \`git cat-file -e\`
  и grep теста. Нет живого коммита/теста → честный «отложен»/«принят с обоснованием», не «закрыт».
- Вердикт release-readiness обязан оставаться ЧЕСТНЫМ: P4.1 (🔴 hocuspocus-эскалация), P4.9/P4.10
  (не исполнялись) — открыты, значит «НЕ готов к релизу». Не подгоняй под «готов».
- Обнови Phase Status / Status Board / Update Log / handoff.md, прогони plan-lint.py.
Коммит: \`docs(plan): актуализация closure-матрицы после закрытия R-04/R-11 (P5.3)\`.
`.trim()

// ─── Прогон ─────────────────────────────────────────────────────────────────
const ROUNDS = 3
const queued = []

phase('Fix')
log('Чиню обе XSS-находки (codeBlock-фенс + URL-атрибуты шва), opus/high')
let fix = await agent(fixPrompt, { label: 'fix:R-04+R-11', phase: 'Fix', model: 'opus', effort: 'high', schema: EXEC_SCHEMA })
if (!fix) {
  return { closed: [], queued: [{ item: 'R-04/R-11', reason: 'исполнитель умер', findings: [] }], nextStep: 'Чинить вручную.' }
}
if (fix.escalation) queued.push({ item: 'R-04/R-11', reason: `эскалация: ${fix.summary}`, findings: [] })

// ── Verify: adversarial-верификация исполнением, до схождения ───────────────
phase('Verify')
let clean = false
let lastHard = []
for (let round = 1; round <= ROUNDS; round++) {
  const v = await agent(verifyPrompt, {
    label: `verify:R-04+R-11${round > 1 ? `#${round}` : ''}`,
    phase: 'Verify',
    agentType: 'vaulter-reviewer',
    model: 'opus',
    effort: 'xhigh',
    schema: REVIEW_SCHEMA,
  })
  if (!v) break

  const hard = v.findings.filter((f) => f.severity === 'Blocker' || f.severity === 'Major')
  lastHard = hard
  if (hard.length === 0) {
    clean = true
    log(`✓ Верификация исполнением пройдена (Minor/Nit: ${v.findings.length})`)
    break
  }
  if (round === ROUNDS) break

  log(`Верификация: ${hard.length} Blocker/Major живы — раунд фиксов ${round}`)
  const refix = await agent(
    `${COLD_START}\n\nАдверсариальная верификация ИСПОЛНЕНИЕМ показала, что класс дефекта НЕ закрыт:\n\n` +
      hard.map((f, i) => `${i + 1}. [${f.severity}] ${f.file}${f.line ? ':' + f.line : ''} — ${f.summary}`).join('\n') +
      `\n\nПочини КЛАСС, а не конкретный вектор из доказательства. Регресс-тест — красный ДО фикса,\n` +
      `гоняется в CI. Гейт зелёный ДО коммита. Коммит: \`fix(...): суть (P5.2)\` — тип латиницей,\n` +
      `subject с РЕАЛЬНЫМ описанием. escalation=true только при упоре в решение вне ⚑.`,
    { label: `refix:R-04+R-11#${round}`, phase: 'Verify', model: 'opus', effort: 'high', schema: EXEC_SCHEMA },
  )
  if (!refix || refix.escalation) {
    if (refix?.escalation) log('Фикс эскалировал — человеку')
    break
  }
}

if (!clean) queued.push({ item: 'R-04/R-11', reason: `верификация держит ${lastHard.length} Blocker/Major`, findings: lastHard })

// ── Matrix: актуализировать closure-матрицу ─────────────────────────────────
phase('Matrix')
log('Обновляю closure-матрицу P5.3 под закрытые R-04/R-11')
const matrix = await agent(matrixPrompt, { label: 'matrix:P5.3', phase: 'Matrix', model: 'sonnet', effort: 'medium', schema: EXEC_SCHEMA })
if (!matrix || matrix.escalation) {
  queued.push({ item: 'P5.3-matrix', reason: `матрица не обновлена: ${matrix?.summary ?? 'агент умер'}`, findings: [] })
} else {
  log('✓ closure-матрица актуализирована')
}

return {
  closed: clean ? ['R-04', 'R-11', 'P5.3-matrix'] : ['P5.3-matrix'],
  verified: clean,
  queued,
  nextStep: queued.length
    ? 'Разбери queued[]. Открыты также P4.1 / P4.9 / P4.10 (MORNING-QUEUE.md).'
    : 'P5 закрыта целиком. Остаются P4.1 / P4.9 / P4.10 (MORNING-QUEUE.md), затем /task:plan-close archive VLT-REMED.',
}

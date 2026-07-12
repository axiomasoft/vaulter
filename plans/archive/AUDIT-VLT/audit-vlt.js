export const meta = {
  name: 'audit-vlt',
  description: 'Сквозной прогон мастер-плана AUDIT-VLT: развёртка фазы → read-only аудит по чеклистам → ревью честности → проверка доказательств → адверсариальная верификация → закрытие фазы',
  whenToUse: 'Когда нужно прогнать одну или несколько фаз плана plans/AUDIT-VLT (P0–P5) параллельно вместо последовательных /task:plan-exec. Аудит read-only: продакшн-код не меняется. args: {phases:["P2","P3"], design:true, evidence:true, verify:true} либо строка "P2 P3".',
  phases: [
    { title: 'Guard', detail: 'Read-only инвариант D2: дерево packages/docs/e2e/hocuspocus-server чистое' },
    { title: 'Probe', detail: 'Скелет-маркер фазы и фактический список items' },
    { title: 'Design', detail: 'Развёртка скелета фазы по plan-protocol (D17)', model: 'opus' },
    { title: 'Design Review', detail: 'DoR-гейт: 16 полей каждого item’а, Code Guidance, конечные Required Reads', model: 'opus' },
    { title: 'Items', detail: 'Параллельные item-агенты: закрытый чеклист C1..Cn, только свой findings-файл' },
    { title: 'Phase Review', detail: 'Ревью честности исполнения: вердикт GREEN/ATTENTION/RED', model: 'opus' },
    { title: 'Repair', detail: 'Один раунд починки items, забракованных ревью' },
    { title: 'Evidence', detail: 'Код-ревью находок: file:line существует, цитата совпадает с кодом, severity по шкале' },
    { title: 'Verify', detail: 'Адверсариальная верификация Blocker/Major тремя скептиками с разными линзами', model: 'opus' },
    { title: 'Close', detail: 'REGISTER, статусы, Phase Handoff, handoff.md, plan-lint, ОДИН коммит на фазу' },
  ],
}

// ─── Общие константы ────────────────────────────────────────────────────────

const PLAN = 'plans/AUDIT-VLT'
const PHP = '/usr/bin/php8.4'
const LINT = 'python3 "/home/vostrikov/projects/packages/swissknifeman/packages/task/scripts/plan-lint.py" plans/AUDIT-VLT'
const TREE = 'git status --porcelain -- packages docs e2e hocuspocus-server'

const SKELETON_MARK = '> СКЕЛЕТ — не детализирован'

// Routing моделей — выжимка plan.md §3. Дефолт: sonnet/medium (чеклист закрыт,
// работа = сверка кода с нормой + file:line). Отклонения — ниже, поимённо.
const SONNET = { model: 'sonnet', effort: 'medium' }
const OPUS = { model: 'opus', effort: 'high' }

// ─── Схемы ──────────────────────────────────────────────────────────────────

const PROBE_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  required: ['phase', 'isSkeleton', 'itemIds'],
  properties: {
    phase: { type: 'string' },
    isSkeleton: { type: 'boolean', description: 'В Phase Context стоит скелет-маркер' },
    itemIds: { type: 'array', items: { type: 'string' }, description: 'ID items в порядке файла фазы' },
    dorClosed: { type: 'boolean', description: 'DoR-чеклист фазы отмечен закрытым' },
    notes: { type: 'string' },
  },
}

const DESIGN_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  required: ['designed', 'itemIds', 'clarificationMarkers', 'lintOk'],
  properties: {
    designed: { type: 'boolean' },
    itemIds: { type: 'array', items: { type: 'string' } },
    clarificationMarkers: { type: 'integer', description: 'Сколько маркеров уточнения осталось (обязано быть 0)' },
    lintOk: { type: 'boolean' },
    decisions: { type: 'array', items: { type: 'string' }, description: 'D#, добавленные в Decision Log' },
    ragFiles: { type: 'array', items: { type: 'string' }, description: 'Созданные findings/<Pn>-*.md с RAG-выжимками' },
    notes: { type: 'string' },
  },
}

const DOR_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  required: ['verdict', 'defects'],
  properties: {
    verdict: { type: 'string', enum: ['READY', 'NOT-READY'] },
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
            enum: ['missing-field', 'empty-code-guidance', 'unbounded-required-reads', 'no-validation-command', 'clarification-marker', 'no-rag-marker', 'checklist-missing', 'scope-not-set'],
          },
          evidence: { type: 'string' },
        },
      },
    },
    summary: { type: 'string' },
  },
}

const FINDING_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  required: ['item', 'findingsFile', 'checksTotal', 'checksVerdicted', 'findings', 'limitations'],
  properties: {
    item: { type: 'string', description: 'ID item\'а, например P2.3' },
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
          norm: { type: 'string', description: 'Нарушенная норма: U2.4 / C2.1 / G2.2 / S3.1 / E6.2 / PT4.2 / ADR-000X' },
          summary: { type: 'string' },
        },
      },
    },
    limitations: { type: 'string', description: 'Что осталось непроверенным и почему' },
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
      description: 'GREEN — фаза исполнена честно; ATTENTION — слабые места, коммит допустим; RED — исполнение недостоверно, коммит запрещён',
    },
    rejectedItems: { type: 'array', items: { type: 'string' } },
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
            enum: ['unverdicted-check', 'no-evidence', 'weasel-wording', 'repro-not-run', 'read-only-violation', 'scope-drift', 'norm-not-cited', 'adr-not-checked', 'duplicate-of-earlier-phase'],
          },
          evidence: { type: 'string', description: 'Цитата из файла находок + путь' },
        },
      },
    },
    summary: { type: 'string' },
  },
}

const EVIDENCE_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  required: ['item', 'checked'],
  properties: {
    item: { type: 'string' },
    checked: {
      type: 'array',
      items: {
        type: 'object',
        additionalProperties: false,
        required: ['id', 'anchorExists', 'quoteMatches', 'severityOk'],
        properties: {
          id: { type: 'string' },
          anchorExists: { type: 'boolean', description: 'file:line существует, строка в пределах файла' },
          quoteMatches: { type: 'boolean', description: 'Процитированный код совпадает с фактическим (±2 строки)' },
          severityOk: { type: 'boolean', description: 'Severity соответствует шкале artifact-review' },
          suggestedSeverity: { type: 'string', enum: ['Blocker', 'Major', 'Minor', 'Nit'] },
          note: { type: 'string' },
        },
      },
    },
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
    treeClean: { type: 'boolean' },
    notes: { type: 'string' },
  },
}

const GUARD_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  required: ['clean', 'output'],
  properties: {
    clean: { type: 'boolean' },
    output: { type: 'string', description: 'Дословный вывод команды' },
    strayRepro: { type: 'string', description: 'Найденные repro-файлы внутри packages/*/tests (D6) или "none"' },
  },
}

// ─── Промпты ────────────────────────────────────────────────────────────────

function guardPrompt(when) {
  return `Ты — гейт read-only инварианта D2 мастер-плана AUDIT-VLT (${when}).

Выполни ровно две команды в корне репозитория vaulter и верни их фактический вывод:

1. ${TREE}
2. find packages -path '*/tests/*' -name '*epro*'

Ничего не правь, ничего не коммить, ничего не откатывай.
clean=true только если первая команда дала ПУСТОЙ вывод.
strayRepro — вывод второй команды или "none", если он пуст (D6: repro живут только в
${PLAN}/artifacts/repro/<item>/).`
}

function probePrompt(phaseId) {
  return `Ты — разведчик фазы ${phaseId} мастер-плана AUDIT-VLT. Read-only, ничего не правишь.

Прочитай ${PLAN}/phases/${phaseId}.md и верни:
- isSkeleton: содержит ли «## Phase Context» строку-маркер «${SKELETON_MARK}»;
- itemIds: список ID из заголовков «### ${phaseId}.N — …» в порядке файла
  (заголовки вроде «### Обязательные оси …» — НЕ items, их не включай);
- dorClosed: все ли пункты «## DoR (Definition of Ready)» отмечены [x];
- notes: одна строка о состоянии фазы.`
}

function designPrompt(phaseId) {
  return `Ты разворачиваешь СКЕЛЕТ фазы ${phaseId} мастер-плана AUDIT-VLT по протоколу
plan-protocol (режим «<PLAN-ID> Pn» команды /task:plan-design). Ты пишешь ТЗ, которое
дешёвая модель исполнит без доступа к этому чату.

## Что прочитать (в этом порядке)

1. ${PLAN}/plan.md — секции 1–6 целиком: Context, Execution Rules, Routing, Status Board,
   Decision Log (D1–D21), Update Log.
2. ${PLAN}/phases/${phaseId}.md — Phase Context и блок «Обязательные оси (вход развёртывания)».
   Этот блок — ВХОД развёртывания, а не пожелание: каждая ось обязана попасть в чеклист item'а.
3. ${PLAN}/artifacts/finding-template.md — схема находки и шкала severity.
4. ${PLAN}/findings/P0-*.md и ${PLAN}/findings/P2-package-tools.md — нормативные RAG-выжимки
   (D16): нормы U/C/S/E/G/T/PT. Их править ЗАПРЕЩЕНО.
5. ${PLAN}/findings/REGISTER.md — что уже найдено в предыдущих фазах (дубли заводить нельзя).
6. Уже развёрнутую фазу ${PLAN}/phases/P1.md или P2.md — как эталон формата item'а.

## Что сделать

1. Замени скелет-маркер полноценным «## Phase Context» (5–10 строк, самодостаточно:
   фаза исполнима без чтения других фаз), с Scope Included / Scope Excluded.
2. Разложи фазу на items «### ${phaseId}.N — Title» — по одному на существующую строку
   «Phase Status». Каждый item несёт ВСЕ 16 полей строками «**Поле:** значение» в порядке:
   Status, Intent, Why, Scope Included, Scope Excluded, Inputs, Files, Required Reads,
   Implementation Rules, Code Guidance, Validation, Deliverables, Completion Notes,
   Pending Work, Known Deviations, Escalation Needed. Пустое значение — символ «—».
3. Implementation Rules КАЖДОГО item'а = закрытый чеклист C1..Cn (D3), выведенный из норм
   RAG-выжимок. Свободное ревью запрещено. Где нужен repro — назови файл и команду.
4. Code Guidance ОБЯЗАТЕЛЕН: что запрещено, что не считается доказательством, когда
   ставить ⚠ partial вместо pass, когда эскалировать (trigger «rule conflict»), запрет
   дублировать находки предыдущих фаз (ссылка на F-… вместо новой строки), запрет правок
   продакшн-кода (D2).
5. Required Reads — конечный упорядоченный список СУЩЕСТВУЮЩИХ файлов (проверь их наличие
   командой, не по памяти). Validation — конкретные команды и критерий зелёного.
6. Несущие внешние факты верифицируй RAG (context7 → perplexity → WebSearch) и ставь маркер
   «RAG:✅ <дата/суть>» либо «[UNVERIFIED]». Выжимку сохрани в
   ${PLAN}/findings/${phaseId}-<slug>.md и сошлись на неё из Inputs/Required Reads.
7. Спорное место — маркер уточнения в тексте или вопрос владельцу; молча принятое
   допущение = дефект. Принятые решения — строкой в «## 5. Decision Log» plan.md
   (с Alternatives), правку плана — строкой в «## 6. Update Log».
8. Синхронизируй «Phase Status» фазы и «4. Phase Index & Status Board» plan.md.
9. Закрой DoR-чеклист фазы: 0 маркеров уточнения, Scope заполнены, Alternatives в D-логе,
   несущие факты с RAG-маркерами.
10. Прогони ${LINT} и почини красное.

## Запреты

- Не меняй файлы вне ${PLAN}/ (аудит read-only, D2) — ни строчки продакшн-кода.
- Не правь ${PLAN}/findings/P0-*.md и другие нормативные выжимки (D16).
- Не закрывай items и не проставляй им статусы кроме ⬜ Not started.
- Не изобретай новые статусы: ровно 6 канонических строк.

Верни: designed, список itemIds, число оставшихся маркеров уточнения (обязано быть 0),
зелёный ли линтер, добавленные D#, созданные RAG-файлы.`
}

function dorPrompt(phaseId, itemIds) {
  return `Ты — DoR-гейт фазы ${phaseId} мастер-плана AUDIT-VLT. Ты НЕ проектируешь фазу заново
и НЕ проводишь аудит. Ты проверяешь, что фаза ГОТОВА к исполнению дешёвой моделью
без доступа к контексту проектирования.

Прочитай:
1. ${PLAN}/phases/${phaseId}.md целиком (items: ${itemIds.join(', ')});
2. ${PLAN}/plan.md — «2. Execution Rules», «5. Decision Log»;
3. Выборочно 3–5 файлов из Required Reads разных items — проверь, что они СУЩЕСТВУЮТ.

## Оси проверки (дефект = строка в defects)

- missing-field: у item'а нет одного из 16 обязательных полей, либо порядок нарушен.
- empty-code-guidance: Code Guidance = «—» или содержит общие слова без запретов.
- unbounded-required-reads: «посмотри по репо», «все файлы каталога», несуществующий путь.
- no-validation-command: Validation без конкретной команды и критерия зелёного.
- clarification-marker: в тексте остались маркеры уточнения.
- no-rag-marker: несущий внешний факт в Intent/Why/Implementation Rules без «RAG:✅» или «[UNVERIFIED]».
- checklist-missing: Implementation Rules не содержит закрытого чеклиста C1..Cn (D3).
- scope-not-set: у фазы или item'а не заполнены Scope Included / Scope Excluded.

Вердикт NOT-READY — если есть хотя бы один дефект осей missing-field, empty-code-guidance,
unbounded-required-reads, checklist-missing или clarification-marker. Остальное — на усмотрение,
но каждый дефект перечисли.

Ты read-only: ничего не правишь и не коммитишь.`
}

function itemPrompt(id, phaseId, extraNote) {
  return `Ты исполняешь РОВНО ОДИН item мастер-плана AUDIT-VLT в репозитории vaulter
(рабочая директория — корень репозитория).

Item: ${id}
Файл фазы: ${PLAN}/phases/${phaseId}.md

## Обязательный порядок

1. Прочитай ${PLAN}/plan.md — секции «2. Execution Rules» и «5. Decision Log» (D1–D21).
2. Прочитай ${PLAN}/artifacts/finding-template.md — схема файла находок и шкала severity.
3. Прочитай в ${PLAN}/phases/${phaseId}.md раздел «### ${id} — …» ЦЕЛИКОМ.
4. Прочитай его Required Reads в указанном порядке. Файла нет → НЕ выдумывай содержимое:
   зафиксируй расхождение плана с кодом в поле limitations и продолжай с остальными.
5. Выполни Implementation Rules буквально: закрытый чеклист C1..Cn.
   Каждая строка обязана получить вердикт: ✅ pass (с file:line), ❌ fail (с находкой),
   ⚠ partial, n/a (с причиной). Формулировки «выглядит корректно», «проблем не нашёл»,
   «вероятно, ок» ЗАПРЕЩЕНЫ — это дефект исполнения.
6. Где Implementation Rules требует repro — напиши временный Pest-тест в
   ${PLAN}/artifacts/repro/${id}/ и прогони: ${PHP} vendor/bin/pest ${PLAN}/artifacts/repro/${id}
   Фактический вывод процитируй в находке.
7. Прочитай ${PLAN}/findings/REGISTER.md: если дефект уже зафиксирован находкой предыдущей
   фазы — НЕ заводи дубль, сошлись на существующий F-… в вердикте строки чеклиста.
8. Напиши файл находок ${PLAN}/findings/${id}-<slug>.md строго по схеме шаблона.

## Жёсткие запреты

- Аудит READ-ONLY (D2). НЕ меняй ни одного файла вне ${PLAN}/.
  Проверь в конце: ${TREE}
  Вывод обязан быть пустым; результат положи в поле treeClean.
- Repro-тесты кладутся ТОЛЬКО в ${PLAN}/artifacts/repro/${id}/ (D6). В packages/*/tests — запрещено.
- НЕ трогай ${PLAN}/findings/REGISTER.md, НЕ меняй статусы в plan.md и в файлах фаз,
  НЕ делай git commit. Это сделает отдельная стадия закрытия фазы (D19).
- RAG-выжимки ${PLAN}/findings/P0-*.md и ${PLAN}/findings/P2-package-tools.md НОРМАТИВНЫ
  и неизменны (D16): сверяй код с ними, не правь их под код. Свежий факт, противоречащий
  выжимке, — строкой в ${PLAN}/findings/P0.2-rag-supplement.md + эскалация (rule conflict).
- До заведения находки сверься с соответствующим docs/adr/*.md (D5): расхождение с
  сознательным решением ADR — находка класса «ADR устарел», а не «баг».
- Нашёл Blocker → НЕ чини (D2). Зафиксируй находку и продолжай чеклист.
${extraNote ? `\n## Особенность фазы\n\n${extraNote}\n` : ''}
PHP-бинарь: ${PHP} (системный php — 8.5, проект требует ^8.4).

Верни структурированный результат: путь к файлу находок, число строк чеклиста, число
вердиктов, список находок, ограничения.`
}

function reviewPrompt(phaseId, itemIds, results) {
  const stats = results
    .filter(Boolean)
    .map(r => `- ${r.item}: файл ${r.findingsFile}, вердиктов ${r.checksVerdicted}/${r.checksTotal}, находок ${r.findings.length}, tree clean ${r.treeClean}`)
    .join('\n')

  return `Ты — независимый ревьюер фазы ${phaseId} мастер-плана AUDIT-VLT. Ты НЕ проводишь аудит
кода заново. Ты проверяешь, что аудит был проведён ЧЕСТНО, и ищешь признаки его имитации.

Отчёты исполнителей:
${stats}

## Что прочитать

1. ${PLAN}/plan.md — «2. Execution Rules» и решения D2, D3, D4, D5, D6, D16.
2. ${PLAN}/artifacts/finding-template.md — обязательная схема файла находок.
3. ${PLAN}/phases/${phaseId}.md — чеклисты C1..Cn каждого item'а (это эталон).
4. КАЖДЫЙ файл ${PLAN}/findings/<item>-*.md для items: ${itemIds.join(', ')}.
5. ${PLAN}/findings/REGISTER.md — чтобы поймать дубли находок предыдущих фаз.

## Оси ревью — ищи дефекты именно этих классов

- unverdicted-check: строка чеклиста из phases/${phaseId}.md отсутствует в файле находок ИЛИ
  не имеет вердикта. Сверь ПОШТУЧНО: число строк C в эталоне против числа строк в отчёте.
- no-evidence: вердикт \`✅ pass\` без \`file:line\`, либо находка без доказательства
  (цитаты кода или вывода repro).
- weasel-wording: «выглядит корректно», «вероятно», «проблем не обнаружено», «скорее всего»,
  «похоже, защищено» — прямой запрет D3. Грепни файлы находок по этим формулировкам.
- repro-not-run: Implementation Rules требует repro для конкретных строк C, но в
  ${PLAN}/artifacts/repro/<item>/ пусто либо вывод прогона не приведён.
- read-only-violation: ${TREE} непуст, либо в packages/*/tests появились repro-файлы (D6).
- scope-drift: item ушёл в чужую ось (аудит производительности внутри фазы безопасности и т.п.).
- norm-not-cited: находка не ссылается на нарушенную норму (U/C/S/E/G/T/PT-пункт или ADR).
- adr-not-checked: находка по теме, у которой есть docs/adr/*.md, без строки «Сверка с ADR».
- duplicate-of-earlier-phase: находка повторяет строку REGISTER из прошлой фазы вместо ссылки.

## Вердикт

- RED — если есть хотя бы один read-only-violation ИЛИ у любого item'а доля вердиктов < 100%
  ИЛИ найден weasel-wording в вердиктах \`pass\`. RED = коммит фазы запрещён.
- ATTENTION — дефекты есть, но они не отравляют выводы.
- GREEN — каждая строка каждого чеклиста имеет вердикт, каждый \`pass\` несёт file:line,
  каждый требуемый repro прогнан, дерево чистое.

Ноль находок в фазе НЕ является основанием для RED сам по себе — но обязан быть подкреплён
доказательствами по каждой строке. Аудит без находок и без доказательств = имитация → RED.

Ты работаешь read-only: ничего не правишь и не коммитишь.`
}

function repairPrompt(id, phaseId, defects) {
  const list = defects.map(d => `- [${d.axis}] ${d.evidence}`).join('\n')

  return `Ревью фазы забраковало твой item ${id}. Переделай ровно то, на что указано.

Дефекты:
${list}

## Что сделать

1. Перечитай раздел «### ${id} — …» в ${PLAN}/phases/${phaseId}.md — чеклист C1..Cn это эталон.
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
   - norm-not-cited / adr-not-checked → допиши ссылку на норму и строку «Сверка с ADR»;
   - duplicate-of-earlier-phase → удали дубль, сошлись на существующий F-… из REGISTER.

ЗАПРЕЩЕНО подгонять вердикт под удобство: если проверка честно даёт ❌ fail — так и пиши,
даже если это Blocker. Аудит READ-ONLY (D2): продакшн-код не чини.
Не трогай REGISTER.md, статусы и git.

Верни обновлённый структурированный результат по своему item'у.`
}

function evidencePrompt(result) {
  const list = result.findings.map(f => `- ${f.id} [${f.severity}/${f.confidence}] «${f.title}» — ${f.file} — норма ${f.norm}`).join('\n')

  return `Ты — код-ревьюер ДОКАЗАТЕЛЬСТВ находок item'а ${result.item} мастер-плана AUDIT-VLT.
Ты не переоткрываешь аудит и не ищешь новых дефектов. Ты проверяешь, что каждая находка
опирается на реальный код, а не на пересказ и не на галлюцинацию.

Находки:
${list}

Файл находок: ${result.findingsFile}

## Для КАЖДОЙ находки

1. anchorExists — открой указанный file:line. Файл существует? Номер строки в пределах файла?
   Строка относится к тому символу/методу, о котором говорит находка?
2. quoteMatches — сверь цитату кода из файла находок с фактическим содержимым (допуск ±2 строки
   на сдвиг). Цитата, которой нет в файле, — грубый дефект: anchorExists=false, quoteMatches=false.
3. severityOk — сверь severity со шкалой ${PLAN}/artifacts/finding-template.md:
   Blocker — эксплуатируемая уязвимость / потеря данных / обход авторизации;
   Major — нарушен инвариант или отсутствует обязательный контроль, эксплуатация требует условий;
   Minor — дефект без прямого риска; Nit — стиль/именование/читаемость.
   Не совпало — верни suggestedSeverity и одну строку обоснования в note.

Ты read-only: не правь ни файл находок, ни код, ни REGISTER. Не коммить.
Сомневаешься, существует ли путь эксплуатации, — это НЕ твоя ось (её закрывает стадия
адверсариальной верификации). Твоя ось — существует ли доказательство физически.`
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
   закрыт. Repro пиши в ${PLAN}/artifacts/repro/verify-${finding.id}/ и гоняй через
   ${PHP} vendor/bin/pest <путь>.
4. Норму читай в ${PLAN}/findings/P0-*.md и ${PLAN}/findings/P2-package-tools.md — они
   нормативны (D16).

Правь только файлы под ${PLAN}/. Продакшн-код не трогай.

При сомнении ставь refuted=true: ложная находка дороже пропущенной на этом шаге —
пропущенную поймает следующий раунд, а ложная отравит бэклог P5.2.

Верни вердикт: опровергнута ли находка, чем именно (с file:line), и надо ли двигать severity.`
}

function closePrompt(phaseId, itemIds, results, extra) {
  const summary = results
    .filter(Boolean)
    .map(r => `- ${r.item}: ${r.findings.length} находок (${r.checksVerdicted}/${r.checksTotal} вердиктов), tree clean: ${r.treeClean}`)
    .join('\n')

  return `Ты закрываешь фазу ${phaseId} мастер-плана AUDIT-VLT. Кода не пишешь, аудит не ведёшь —
только сводишь артефакты и коммитишь.

Итоги исполнителей:
${summary}

## Что сделать

1. Прочитай КАЖДЫЙ файл ${PLAN}/findings/<item>-*.md для items: ${itemIds.join(', ')}.
2. Допиши все находки строками в ${PLAN}/findings/REGISTER.md (append-only, существующие
   строки не править). Колонки: ID | Item | Severity | Уверенность | Заголовок | Где (file:line) | Норма.
3. Проставь статусы: в ${PLAN}/phases/${phaseId}.md таблица «Phase Status» и в
   ${PLAN}/plan.md «4. Phase Index & Status Board». Статус item'а — 🟢 Done, если чеклист
   закрыт полностью; 🟠 Done with deviations, если остались ⚠ partial; 🔴 Blocked, если
   исполнитель эскалировал. Статусы — 6 канонических строк байт-в-байт.
   Столбец «Items 🟢/всего» в Status Board обнови фактическим числом.
4. Заполни «## Phase Handoff» в ${PLAN}/phases/${phaseId}.md: что сдали, находки по severity,
   известные отклонения.
5. Перезапиши ${PLAN}/handoff.md целиком: заголовок «# HANDOFF — <ISO-дата> — after <последний
   закрытый item>», Next в форме launch-block (таблица с | Model | + code-block с голой
   командой), Done / Remaining / Sources of truth / Open risks / Workarounds-Deferred-Open questions.
   Все подтверждённые Blocker'ы обязаны попасть в Open risks. Дату возьми из: date +%F
6. Допиши строку в «## 6. Update Log» plan.md (≤2 строк, без пересказа Completion Notes).
7. Прогони линтер: ${LINT} — красное почини.
8. Коммит-гейт: убедись, что ${TREE} пуст (положи результат в treeClean).
   Затем: git add plans/AUDIT-VLT && git commit
   Сообщение — Conventional Commits, тип docs(plan) или chore(audit), тело на русском,
   одна строка сути на item. Продакшн-код в коммите отсутствовать обязан.
   Если дерево грязное — коммит НЕ делай, верни commit="none" и опиши в notes.
${extra || ''}
Верни: sha коммита, число дописанных строк REGISTER, зелёный ли линтер, чистое ли дерево.`
}

// ─── Реестр фаз: волны исполнения и routing моделей (plan.md §3) ────────────

const PHASES = {
  P0: {
    waves: [[{ id: 'P0.1', ...SONNET }], [{ id: 'P0.2', ...OPUS }, { id: 'P0.3', ...SONNET }]],
    note: 'P0.2 верифицирует внешние версионно-привязанные факты через context7/perplexity-web (RAG). Дешёвая модель галлюцинирует версии и статусы CVE — используй инструменты, а не память.',
  },
  P1: {
    waves: [
      [
        { id: 'P1.1', ...OPUS },
        { id: 'P1.2', ...SONNET },
        { id: 'P1.3', ...SONNET },
        { id: 'P1.4', ...SONNET },
        { id: 'P1.5', ...SONNET },
        { id: 'P1.6', ...OPUS },
        { id: 'P1.7', ...SONNET },
        { id: 'P1.8', ...SONNET },
      ],
      [{ id: 'P1.9', ...SONNET }, { id: 'P1.11', ...SONNET }, { id: 'P1.12', ...SONNET }],
      [{ id: 'P1.10', ...SONNET }],
    ],
  },
  P2: {
    // P2.4 «C3 connection» ссылается на вердикт P2.3 «C6»; P2.5 «C2» — на вывод
    // vendor:publish --list из P2.2 «C7». Отсюда две волны.
    waves: [
      [{ id: 'P2.1', ...SONNET }, { id: 'P2.2', ...SONNET }, { id: 'P2.3', ...SONNET }],
      [{ id: 'P2.4', ...SONNET }, { id: 'P2.5', ...SONNET }],
    ],
    note: 'FlexCRM (/home/vostrikov/projects/flexcrm) — read-only источник фактов (D21): ни одной команды, меняющей его файлы, БД или зависимости. Пруф в конце: git -C /home/vostrikov/projects/flexcrm status --porcelain пуст.',
  },
  P3: {
    waves: [
      [
        { id: 'P3.1', ...SONNET },
        { id: 'P3.2', ...SONNET },
        { id: 'P3.3', ...SONNET },
        { id: 'P3.4', ...SONNET },
        { id: 'P3.5', ...SONNET },
        { id: 'P3.6', ...SONNET },
      ],
    ],
  },
  P4: {
    waves: [
      [
        { id: 'P4.1', ...SONNET },
        { id: 'P4.2', ...SONNET },
        { id: 'P4.3', ...SONNET },
        { id: 'P4.4', ...SONNET },
        { id: 'P4.5', ...SONNET },
      ],
    ],
    note: 'P4.4 сверяет актуальные CVE/матрицу версий — используй RAG-лестницу (context7 → perplexity-web → WebSearch), не память.',
  },
  P5: {
    // Синтез строго последовательный: отчёт → бэклог → верификация митигаций.
    waves: [[{ id: 'P5.1', ...OPUS }], [{ id: 'P5.2', ...OPUS }], [{ id: 'P5.3', ...OPUS }]],
    note: `Фаза синтеза. Источник — ${PLAN}/findings/REGISTER.md и все findings/<item>-*.md.
Sanity-гейт P5.1: «ноль находок» по любой оси обязан быть ОБОСНОВАН (почему ось пуста),
а не констатирован. P5.3: критерий read-only — между первым и последним коммитом аудита
нет коммитов, изменяющих packages/ (git log --oneline <first>..<last> -- packages).
Ремедиация кода здесь НЕ делается (D2): P5.2 порождает бэклог и рекомендацию о follow-up плане.`,
    skipVerify: true,
    skipEvidence: true,
  },
}

const VERIFY_LENSES = [
  'корректность: верно ли прочитан код и семантика',
  'достижимость: есть ли реальный путь эксплуатации от недоверенного актора',
  'намеренность: не является ли это сознательным решением ADR или задокументированным ограничением',
]

// ─── Разбор args ────────────────────────────────────────────────────────────

const ORDER = ['P0', 'P1', 'P2', 'P3', 'P4', 'P5']

function parseArgs(a) {
  if (!a) return { phases: ['P2', 'P3', 'P4', 'P5'] }
  if (typeof a === 'string') {
    const ids = a.toUpperCase().match(/P[0-5]/g)
    return { phases: ids && ids.length ? ids : ['P2', 'P3', 'P4', 'P5'] }
  }
  if (Array.isArray(a)) return { phases: a }
  return a
}

const opts = parseArgs(args)
const requested = ORDER.filter(p => (opts.phases || []).includes(p))
const doDesign = opts.design !== false
const doVerify = opts.verify !== false
const doEvidence = opts.evidence !== false

if (requested.length === 0) {
  return { halted: 'args', reason: 'Не указана ни одна фаза P0–P5', hint: 'args: {phases:["P2"]} либо строка "P2 P3"' }
}

log(`AUDIT-VLT: фазы ${requested.join(' → ')} · design=${doDesign} · evidence=${doEvidence} · verify=${doVerify}`)

// ─── Стадии ─────────────────────────────────────────────────────────────────

async function guard(when) {
  phase('Guard')
  const g = await agent(guardPrompt(when), {
    label: `read-only guard (${when})`,
    phase: 'Guard',
    schema: GUARD_SCHEMA,
    model: 'sonnet',
    effort: 'low',
  })
  if (!g) {
    log('⚠ guard не вернул результат — продолжаем, но D2 не подтверждён')
    return true
  }
  if (!g.clean) log(`🔴 D2 нарушен: дерево грязное\n${g.output}`)
  if (g.strayRepro && g.strayRepro !== 'none') log(`🔴 D6 нарушен: repro в packages/*/tests\n${g.strayRepro}`)
  return g.clean
}

async function designPhase(phaseId, probe) {
  if (!probe.isSkeleton) return { ok: true, itemIds: probe.itemIds }

  if (!doDesign) {
    return {
      ok: false,
      reason: `Фаза ${phaseId} — скелет (D17), а design выключен. Разверни её: /task:plan-design AUDIT-VLT ${phaseId}`,
    }
  }

  phase('Design')
  const d = await agent(designPrompt(phaseId), {
    label: `${phaseId} design: развёртка скелета`,
    phase: 'Design',
    schema: DESIGN_SCHEMA,
    model: 'opus',
    effort: 'high',
  })

  if (!d || !d.designed) {
    return { ok: false, reason: `Развёртка фазы ${phaseId} не состоялась` }
  }
  if (d.clarificationMarkers > 0) {
    return { ok: false, reason: `${phaseId}: осталось ${d.clarificationMarkers} маркеров уточнения — нужен человек` }
  }
  log(`${phaseId} развёрнута: items ${d.itemIds.join(', ')}, D# ${(d.decisions || []).join(', ') || '—'}, lint ${d.lintOk ? 'зелёный' : 'КРАСНЫЙ'}`)

  phase('Design Review')
  const dor = await agent(dorPrompt(phaseId, d.itemIds), {
    label: `${phaseId} DoR-гейт`,
    phase: 'Design Review',
    schema: DOR_SCHEMA,
    model: 'opus',
    effort: 'xhigh',
  })

  if (dor && dor.verdict === 'NOT-READY') {
    const list = dor.defects.map(x => `  - ${x.item} [${x.axis}] ${x.evidence}`).join('\n')
    return { ok: false, reason: `${phaseId}: DoR-гейт вернул NOT-READY\n${list}` }
  }

  return { ok: true, itemIds: d.itemIds }
}

async function runItems(phaseId, itemIds, note) {
  const spec = PHASES[phaseId]
  const known = spec ? spec.waves.flat() : []
  const waves = spec && known.length === itemIds.length ? spec.waves : [itemIds.map(id => ({ id, ...SONNET }))]

  phase('Items')
  const out = []
  for (const wave of waves) {
    const res = await parallel(
      wave.map(it => () =>
        agent(itemPrompt(it.id, phaseId, note), {
          label: `${it.id} audit (${it.model || 'sonnet'}/${it.effort || 'medium'})`,
          phase: 'Items',
          schema: FINDING_SCHEMA,
          model: it.model,
          effort: it.effort,
        })
      )
    )
    out.push(...res.filter(Boolean))
    log(`${phaseId}: волна из ${wave.length} items закрыта (всего ${out.length}/${itemIds.length})`)
  }
  return out
}

async function phaseGate(phaseId, itemIds, results) {
  phase('Phase Review')
  const review = await agent(reviewPrompt(phaseId, itemIds, results), {
    label: `${phaseId} review: честность исполнения`,
    phase: 'Phase Review',
    schema: REVIEW_SCHEMA,
    model: 'opus',
    effort: 'xhigh',
  })

  if (!review) {
    log(`⚠ ${phaseId}: ревьюер не вернул вердикт — считаем ATTENTION`)
    return { verdict: 'ATTENTION', results }
  }

  log(`${phaseId} review: ${review.verdict}, дефектов ${review.defects.length}, на переделку ${review.rejectedItems.length}`)

  if (review.verdict === 'GREEN' || review.rejectedItems.length === 0) {
    return { verdict: review.verdict, results }
  }

  phase('Repair')
  const repaired = await parallel(
    review.rejectedItems.map(id => () => {
      const defects = review.defects.filter(d => d.item === id)
      return agent(repairPrompt(id, phaseId, defects), {
        label: `${id} repair (${defects.length} дефектов)`,
        phase: 'Repair',
        schema: FINDING_SCHEMA,
        model: 'sonnet',
        effort: 'high',
      })
    })
  )

  const merged = results.filter(Boolean).map(r => repaired.filter(Boolean).find(x => x.item === r.item) || r)

  const recheck = await agent(reviewPrompt(phaseId, itemIds, merged), {
    label: `${phaseId} re-review после починки`,
    phase: 'Phase Review',
    schema: REVIEW_SCHEMA,
    model: 'opus',
    effort: 'xhigh',
  })

  const verdict = recheck ? recheck.verdict : 'ATTENTION'
  log(`${phaseId} re-review: ${verdict}`)
  if (verdict === 'RED') log(`🔴 ESCALATION-REQUIRED: ${phaseId} — исполнение недостоверно после раунда починки. Коммита не будет.`)

  return { verdict, results: merged }
}

async function evidencePass(phaseId, results) {
  const withFindings = results.filter(r => r.findings.length > 0)
  if (withFindings.length === 0) return { bad: [], checked: 0 }

  phase('Evidence')
  const reports = await parallel(
    withFindings.map(r => () =>
      agent(evidencePrompt(r), {
        label: `evidence ${r.item} (${r.findings.length} находок)`,
        phase: 'Evidence',
        schema: EVIDENCE_SCHEMA,
        model: 'sonnet',
        effort: 'medium',
      })
    )
  )

  const bad = []
  let checked = 0
  for (const rep of reports.filter(Boolean)) {
    for (const c of rep.checked) {
      checked++
      if (!c.anchorExists || !c.quoteMatches) bad.push({ id: c.id, item: rep.item, why: 'доказательство не подтверждено', note: c.note })
      else if (!c.severityOk) bad.push({ id: c.id, item: rep.item, why: `severity → ${c.suggestedSeverity}`, note: c.note })
    }
  }
  log(`${phaseId} evidence: проверено ${checked} находок, проблемных ${bad.length}`)
  return { bad, checked }
}

async function verifyPass(phaseId, results, unverifiable) {
  const dead = new Set(unverifiable.filter(b => b.why === 'доказательство не подтверждено').map(b => b.id))
  const serious = results.flatMap(r => r.findings.filter(f => (f.severity === 'Blocker' || f.severity === 'Major') && !dead.has(f.id)))

  if (serious.length === 0) {
    log(`${phaseId}: находок Blocker/Major нет — верификация пропущена. Ноль серьёзных находок сам по себе не вердикт (P5.1 sanity-гейт).`)
    return []
  }

  log(`${phaseId} verify: ${serious.length} находок × 3 скептика`)
  phase('Verify')

  return await parallel(
    serious.map(f => () =>
      parallel(
        VERIFY_LENSES.map((lens, i) => () =>
          agent(verifyPrompt(f, lens), {
            label: `verify ${f.id} · линза ${i + 1}`,
            phase: 'Verify',
            schema: VERDICT_SCHEMA,
            model: f.severity === 'Blocker' ? 'opus' : 'sonnet',
            effort: 'high',
          })
        )
      ).then(votes => {
        const v = votes.filter(Boolean)
        const refutedCount = v.filter(x => x.refuted).length
        return { finding: f, survives: refutedCount < 2, refutedCount, votes: v.map(x => x.reason) }
      })
    )
  )
}

// ─── Прогон ─────────────────────────────────────────────────────────────────

const state = { phases: [], allResults: [], commits: [], halted: null }

if (!(await guard('до старта'))) {
  return {
    halted: 'guard',
    reason: 'Рабочее дерево вне plans/ грязное — D2 (read-only) не гарантируется. Закоммить или откати изменения и перезапусти.',
  }
}

for (const phaseId of requested) {
  const spec = PHASES[phaseId] || {}

  phase('Probe')
  const probe = await agent(probePrompt(phaseId), {
    label: `${phaseId} probe: скелет и items`,
    phase: 'Probe',
    schema: PROBE_SCHEMA,
    model: 'sonnet',
    effort: 'low',
  })

  if (!probe || probe.itemIds.length === 0) {
    state.halted = phaseId
    log(`🔴 ${phaseId}: разведка не нашла items — остановка`)
    break
  }

  const design = await designPhase(phaseId, probe)
  if (!design.ok) {
    state.halted = phaseId
    log(`🔴 ${phaseId}: ${design.reason}`)
    state.escalation = design.reason
    break
  }

  const itemIds = design.itemIds
  const results = await runItems(phaseId, itemIds, spec.note)

  if (results.length === 0) {
    state.halted = phaseId
    state.escalation = `${phaseId}: ни один item-агент не вернул результат`
    break
  }

  const gate = await phaseGate(phaseId, itemIds, results)
  if (gate.verdict === 'RED') {
    state.halted = phaseId
    state.escalation = `ESCALATION-REQUIRED: ${phaseId} — ревью честности RED после раунда починки. Переделать вручную (/task:plan-exec), затем /task:plan-audit AUDIT-VLT ${phaseId}.`
    break
  }

  const evi = doEvidence && !spec.skipEvidence ? await evidencePass(phaseId, gate.results) : { bad: [], checked: 0 }
  const verified = doVerify && !spec.skipVerify ? await verifyPass(phaseId, gate.results, evi.bad) : []

  const refuted = verified
    .filter(Boolean)
    .filter(v => !v.survives)
    .map(v => `- ${v.finding.id} «${v.finding.title}» — опровергнута ${v.refutedCount}/3 скептиками`)

  const badEvidence = evi.bad.map(b => `- ${b.id} (${b.item}) — ${b.why}${b.note ? `: ${b.note}` : ''}`)

  let extra = ''
  if (refuted.length) {
    extra += `\n## Адверсариальная верификация\n\nЭти находки ОПРОВЕРГНУТЫ большинством скептиков — в REGISTER их НЕ вносить,
но в файле находок пометить строкой «опровергнуто верификацией, см. artifacts/repro/verify-<id>/»:\n${refuted.join('\n')}\n`
  } else if (doVerify && !spec.skipVerify) {
    extra += '\n## Адверсариальная верификация\n\nВсе находки Blocker/Major пережили верификацию.\n'
  }
  if (badEvidence.length) {
    extra += `\n## Код-ревью доказательств\n\nЭти находки не прошли проверку доказательства (file:line/цитата/severity).
Находки с неподтверждённым доказательством в REGISTER НЕ вносить; для несовпавшей severity — исправить severity
в файле находок и в строке REGISTER:\n${badEvidence.join('\n')}\n`
  }
  extra += `\nЕсли среди подтверждённых находок есть хотя бы один Blocker — статус плана в plan.md остаётся
🟡 In progress, а Blocker'ы перечисляются в handoff.md → Open risks (D7).\n`

  phase('Close')
  const closed = await agent(closePrompt(phaseId, itemIds, gate.results, extra), {
    label: `${phaseId} close: REGISTER + статусы + handoff + коммит`,
    phase: 'Close',
    schema: CLOSE_SCHEMA,
    model: 'sonnet',
    effort: 'low',
  })

  log(`${phaseId} закрыт (${gate.verdict}): commit ${closed ? closed.commit : 'n/a'}, lint ${closed && closed.lintOk ? 'зелёный' : 'ПРОВАЛ'}`)

  if (closed && closed.commit && closed.commit !== 'none') state.commits.push(`${phaseId}:${closed.commit}`)

  state.allResults.push(...gate.results)
  state.phases.push({
    phase: phaseId,
    verdict: gate.verdict,
    items: itemIds.length,
    findings: gate.results.reduce((n, r) => n + r.findings.length, 0),
    evidenceChecked: evi.checked,
    evidenceBad: evi.bad.length,
    refuted: refuted.length,
    commit: closed ? closed.commit : 'none',
    lintOk: closed ? closed.lintOk : false,
  })

  if (closed && !closed.lintOk) log(`⚠ plan-lint красный после ${phaseId} — следующая фаза запускается, но handoff сомнителен`)

  if (!(await guard(`после ${phaseId}`))) {
    state.halted = phaseId
    state.escalation = `${phaseId}: после закрытия фазы дерево вне plans/ грязное — D2 нарушен, следующие фазы не запускаются.`
    break
  }
}

// ─── Итог ───────────────────────────────────────────────────────────────────

const bySeverity = { Blocker: 0, Major: 0, Minor: 0, Nit: 0 }
for (const r of state.allResults) for (const f of r.findings) bySeverity[f.severity]++

return {
  phasesRun: state.phases,
  halted: state.halted,
  escalation: state.escalation || null,
  itemsClosed: state.allResults.map(r => r.item),
  checks: state.allResults.reduce((n, r) => n + r.checksTotal, 0),
  verdicts: state.allResults.reduce((n, r) => n + r.checksVerdicted, 0),
  findingsBySeverity: bySeverity,
  commits: state.commits,
  treeDirty: state.allResults.filter(r => r.treeClean === false).map(r => r.item),
  limitations: state.allResults.map(r => `${r.item}: ${r.limitations}`),
}

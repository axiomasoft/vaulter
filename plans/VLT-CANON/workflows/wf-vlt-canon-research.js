// research-fanout (шаблон 1) под VLT-CANON: движки-редакторы + топология фронта.
// НЕ дублирует RAG VLT-HSEAM (editor-extension-models / laravel-registry-patterns /
// capability-taxonomy уже сделаны — см. plans/VLT-HSEAM/findings). Здесь три НОВЫХ фронта:
// упаковка фронтенд-движков, drive-open-with-резолв, изоляция default-движка за контрактом.
export const meta = {
  name: 'vlt-canon-research',
  description: 'RAG-развед по 3 фронтам pluggable-editor-архитектуры + адверсариальная проверка',
  whenToUse: 'Research-стадия VLT-CANON (Часть 1 брифа); запуск через scriptPath',
  phases: [
    { title: 'Research', detail: '3 фронта параллельно — perplexity-web', model: 'sonnet' },
    { title: 'Verify', detail: 'адверсариальная проверка load-bearing утверждений', model: 'sonnet' },
  ],
}

// ── Конфиг (embedded) ────────────────────────────────────────────────────────
const TOPICS = [
  {
    key: 'frontend-engine-packaging',
    brief: [
      'Как Laravel-экосистемные пакеты организуют и ДИСТРИБУТИРУЮТ ФРОНТЕНД (JS/Vue/Livewire) рядом с backend-PHP-пакетами, 2025-2026.',
      'Конкретно сравнить три модели раскладки в монорепо: (1) backend-PHP-пакеты и frontend-пакеты В ОДНОМ packages/* дереве; (2) фронт в resources/js внутри PHP-пакета (публикуется/собирается Vite); (3) отдельные npm-пакеты вне PHP-дерева.',
      'Эталоны: Filament v4 (asset registration, FilamentAsset::register, Vite-plugin, кастомные поля/виджеты как плагины), Laravel Nova (tools/cards/fields — mix.js/vite, Nova.booting component registration), Statamic v5 addons (addon Vite, publishables, JS component registry). Как каждый версионирует фронт против backend и как хост регистрирует кастомный компонент по имени.',
      'Также: нейминг таких пакетов — единый "view"-пакет vs per-engine пакеты vs "core + engines". Что делают Filament/Nova/Statamic (моно-asset vs per-plugin asset).',
      'Границы: НЕ нужно как писать TipTap-расширение; нужно КАК упаковать/дистрибутировать/зарегистрировать фронтенд-движок в Laravel-package-экосистеме.',
    ].join(' '),
  },
  {
    key: 'drive-editor-resolution',
    brief: [
      'Архитектура "double-click файл → редактор резолвится по типу файла" в облачных file-storage системах (Google-Drive-модель), 2025-2026.',
      'Как устроен поток: opaque file handle/id → СЕРВЕР резолвит подходящий редактор по mime/extension → тонкий КЛИЕНТ получает ДЕСКРИПТОР (какой компонент монтировать + capabilities + open-route/токен), а не хардкодит редактор.',
      'Эталоны и их протоколы: WOPI (discovery.xml, CheckFileInfo, action urls по расширению — Microsoft/Collabora/OnlyOffice), Nextcloud Viewer API (registerHandler по mime, OCA.Viewer), Google Drive "Open with app" association (Drive UI integration, default/secondary apps по mimeType), Box/Dropbox open-with. Как сервер отдаёт клиенту "чем открыть" + токен доступа.',
      'Ключевой вопрос решения: резолвить движок ДИНАМИЧЕСКИ по mime на каждом открытии vs ПЕРСИСТИТЬ handler-id на узле. Что делают перечисленные системы и почему (протухание при смене движка).',
      'Границы: интересует архитектура резолва и контракт server→client дескриптора, НЕ конкретный wire-format конкретного вендора построчно.',
    ].join(' '),
  },
  {
    key: 'editor-driver-isolation',
    brief: [
      'Изоляция ДЕФОЛТНОГО rich-text/документного движка (TipTap) за подменяемым контрактом-драйвером, чтобы он не был вшит в приложение, 2025-2026.',
      'Паттерны: один контракт DocumentEngine/Handler + Manager, резолвящий драйвер по mime/extension; как несколько движков (TipTap, Univer для таблиц, OnlyOffice/Collabora для office, image/pdf-preview) СОСУЩЕСТВУЮТ за одним контрактом; front+back split одного драйвера (PHP отдаёт handler-descriptor ↔ JS монтирует компонент по имени движка).',
      'Актуальное состояние библиотек-движков как ИЗОЛИРУЕМЫХ драйверов: TipTap v3 (headless, что версия несёт для встраивания/collab с Yjs/Hocuspocus), Univer (spreadsheet/doc, embeddability, лицензия), сравнение с ProseMirror-напрямую. Годятся ли они за общим контрактом без протечки специфики в ядро.',
      'Валидация паттерна: bespoke узкий контракт vs Illuminate\\Support\\Manager для реестра драйверов в ПУБЛИЧНОМ пакете (statefulness/$app-coupling/__call) — что рекомендуют для BC-стабильного публичного API пакета.',
      'Границы: НЕ таксономия capabilities (уже сделана в VLT-HSEAM); фокус — механика изоляции default-движка и мульти-драйвер-сосуществование.',
    ].join(' '),
  },
]

const PROJECT_CONTEXT = [
  'vaulter — Laravel-МОНОРЕПО из 6 PHP-пакетов (core/drive/documents/media/filament/permissions-azgard) + npm @vaulter/vue,',
  'editor-agnostic file-storage (Google-Drive-модель узлового дерева). Уже есть: реестр DocumentHandler (mime/ext-диспатч,',
  'capabilities, priority, fallback DownloadOnly) в packages/documents; фронтовый шов componentMap по handler.id в',
  'VaulterDocumentNode.vue; TipTap — дефолтный движок, но сейчас его специфика ПРОТЕКАЕТ в core (контракты/валидатор).',
  'Цель — движки-редакторы как плагины + финальный структурный канон. Пакет пре-1.0 (переименования пока бесплатны по SemVer).',
].join(' ')

const RESEARCH_MODEL = { model: 'sonnet', effort: 'high' }
const VERIFY_MODEL = { model: 'sonnet', effort: 'medium' }
const VERIFY_TOP_N = 6

// ── Схемы ────────────────────────────────────────────────────────────────────
const FINDINGS_SCHEMA = {
  type: 'object',
  properties: {
    topic: { type: 'string' },
    findings: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          claim: { type: 'string', description: 'Факт/практика одним плотным абзацем, конкретно, с версиями/именами' },
          source_url: { type: 'string' },
          source_title: { type: 'string' },
          load_bearing: { type: 'boolean', description: 'true если на этом будет стоять проектное решение vaulter' },
          applicability: { type: 'string', description: 'Как это ложится/не ложится на vaulter (1-2 фразы)' },
        },
        required: ['claim', 'source_url', 'source_title', 'load_bearing', 'applicability'],
      },
    },
    recommendations: { type: 'array', items: { type: 'string' }, description: 'Конкретные рекомендации под vaulter' },
    open_questions: { type: 'array', items: { type: 'string' } },
  },
  required: ['topic', 'findings', 'recommendations', 'open_questions'],
}

const VERIFY_SCHEMA = {
  type: 'object',
  properties: {
    verdicts: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          claim: { type: 'string' },
          verdict: { type: 'string', enum: ['CONFIRMED', 'REFUTED', 'UNVERIFIED'] },
          note: { type: 'string' },
          counter_source_url: { type: 'string' },
        },
        required: ['claim', 'verdict', 'note'],
      },
    },
  },
  required: ['verdicts'],
}

// ── Промпты ──────────────────────────────────────────────────────────────────
function researchPrompt(t) {
  return [
    `RAG-research по теме «${t.key}». Контекст проекта: ${PROJECT_CONTEXT}`,
    `Тема: ${t.brief}`,
    `Инструменты: сначала ToolSearch с query "select:mcp__perplexity-web__search_deep,mcp__perplexity-web__search_advanced,mcp__perplexity-web__search" — загрузи схемы. Начни с ОДНОГО search_deep по теме целиком, затем 5–8 уточняющих search/search_advanced по конкретным вендорам/версиям и спорным местам. Собственное сомнение = ещё один запрос.`,
    `Дисциплина запросов (query-craft): версии/стек/таймфрейм 2025-2026, формат "X vs Y", named tools (Filament v4, Nova 5, Statamic 5, WOPI, Nextcloud Viewer, TipTap v3, Univer). НЕ голые "best practices".`,
    `Каждое утверждение — с URL источника. Чего нет в источниках — в open_questions, не выдумывать. load_bearing=true только для утверждений, на которых будет стоять проектное решение vaulter. Факт без источника = префикс [UNVERIFIED] прямо в claim.`,
    `Каждой находке заполни applicability — как это ложится на vaulter (реестр DocumentHandler уже есть; пакет пре-1.0).`,
    `Верни строго по схеме. Финальный текст = данные, не сообщение человеку.`,
  ].join('\n')
}

function verifyPrompt(t, res) {
  const loadBearing = res.findings.filter(f => f.load_bearing).slice(0, VERIFY_TOP_N)
  const claims = loadBearing.length ? loadBearing : res.findings.slice(0, 4)
  return [
    `Адверсариальная проверка research-находок по теме «${t.key}». Задача — ОПРОВЕРГНУТЬ каждое утверждение свежим независимым поиском (особенно версии библиотек и "как делает вендор X").`,
    `Инструменты: ToolSearch "select:mcp__perplexity-web__search_advanced,mcp__perplexity-web__search", затем 1-2 контрольных запроса на утверждение ДРУГИМИ формулировками.`,
    `Утверждения:`,
    ...claims.map((f, i) => `${i + 1}. ${f.claim} [источник: ${f.source_url}]`),
    `Вердикты: CONFIRMED только при независимом подтверждении; сомнение = UNVERIFIED; противоречие источникам = REFUTED с counter_source_url. Верни строго по схеме.`,
  ].join('\n')
}

// ── Оркестрация: pipeline без барьера ────────────────────────────────────────
log(`vlt-canon-research: ${TOPICS.length} фронта, verify без барьера (pipeline)`)

const results = await pipeline(
  TOPICS,
  t => agent(researchPrompt(t), {
    label: `research:${t.key}`, phase: 'Research',
    ...RESEARCH_MODEL, schema: FINDINGS_SCHEMA,
  }),
  (res, t) => {
    if (!res || !res.findings || !res.findings.length) return null
    return agent(verifyPrompt(t, res), {
      label: `verify:${t.key}`, phase: 'Verify',
      ...VERIFY_MODEL, schema: VERIFY_SCHEMA,
    }).then(v => ({ key: t.key, research: res, verify: v ? v.verdicts : [] }))
  }
)

const ok = results.filter(Boolean)
const dropped = TOPICS.filter(t => !ok.some(r => r.key === t.key)).map(t => t.key)
if (dropped.length) log(`ВНИМАНИЕ: фронты без результата (пересобрать в main loop): ${dropped.join(', ')}`)

return { topics: ok, dropped }

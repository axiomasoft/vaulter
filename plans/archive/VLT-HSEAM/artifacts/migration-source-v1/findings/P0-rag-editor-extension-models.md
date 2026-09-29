# P0 — RAG: extension-модели редакторов (TipTap/ProseMirror, Editor.js)

**Дата:** 2026-07-10 · **Метод:** perplexity-web + context7 + первоисточники · **Статус:** verified, флаги внизу

## TipTap / ProseMirror

- Три базовых класса расширений: `Extension` (поведение, без схемы), `Node`, `Mark`. Регистрация —
  массив `extensions: [...]` в конструкторе редактора; глобального реестра нет, массив = реестр
  инстанса. `configure()` возвращает клон, `extend()` — override с `this.parent?.()`,
  `addExtensions()` — композиция зависимостей (StarterKit = обёртка). `priority` (default 100)
  сортирует; коллизия имён — «последний выигрывает» с warning.
- **«Тип документа» НЕ first-class: одна ProseMirror-схема на редактор, заморожена при создании.**
  Несколько видов документов ⇒ несколько конфигураций редактора, не переключение схем.
- **MIME-диспатч вне скоупа TipTap** — явно ответственность host-приложения.
- Collab и versioning — НЕ свойства ноды, а отдельные расширения-слои
  (`@tiptap/extension-collaboration` на Yjs; Snapshot — Pro-фича).
- Актуальность: TipTap v3 — текущий стабильный мажор; extension-API стабилен.

## Editor.js

- Реестр = map `tools: { <typeName>: ToolClass|settings }`; **ключ map = персистентный
  дискриминатор `type` в JSON**, отвязан от имени класса.
- Block Tool: обязательны только `render()` + `save()`; остальное opt-in: `validate()`,
  `onPaste` + static `pasteConfig`, `sanitize`, `conversionConfig`, `merge`, lifecycle-хуки.
  Категории через static-маркеры (`isInline`, `isTune`).
- Сохранение: плоский список блоков `{id, type, data}` — discriminated union; контраст с
  единым деревом TipTap.
- **Нативного fallback для неизвестного `type` НЕТ** — продакшн-паттерн: свой «unsupported
  block»-tool, сохраняющий исходный payload.

## Дистилляция для vaulter (сверка с текущим дизайном)

1. Ключ регистрации = стабильный slug, отвязанный от класса → у vaulter уже так (`id(): string`).
2. Capabilities = минимальное обязательное ядро + opt-in остальное → уже так (downloadable=true
   базово, остальное opt-in).
3. Collab/versioning — слоистые сервисы, не пер-хендлерные булевы → **дельта**: у vaulter
   `collaborative` — флаг в `DocumentCapabilities`; допустимо, но эволюцию держать сервисной.
4. Явный fallback обязателен (ни одна библиотека его не даёт) → у vaulter уже есть
   `DownloadOnlyHandler` ('*', priority -1000). ✅ сильная сторона.
5. PHP-форма: интерфейс (чистый контракт) + опциональный Abstract-base с дефолтами → vaulter:
   интерфейс есть, Abstract-base — кандидат в DX-улучшение.
6. Один хендлер = один вид контента; резолвер сверху, не god-handler → уже так.
7. Хранить нативный JSON редактора verbatim, валидация на границе → уже так
   (Opaque/DocumentContent + пер-хендлерный валидатор).
8. Priority + детерминированный tiebreak при пересечении → уже так.
9. **Дельта-сигнал (отклонён в ADR, D7):** «персистить резолвнутый type на Node» — vaulter резолвит
   динамически по mime/ext (как WOPI/Nextcloud); персист денормализует и протухает.

## Не удалось верифицировать

- Точный патч `@tiptap/core` 3.x; тариф Snapshot-фичи; «last registered = highest plugin
  priority» — из DeepWiki-выжимки, не первоисточника.

## Источники

tiptap.dev/docs/editor/core-concepts/extensions · tiptap.dev/docs/editor/core-concepts/schema ·
deepwiki.com/ueberdosis/tiptap/1.1-architecture-overview · tiptap.dev/blog/release-notes ·
editorjs.io/getting-started · editorjs.io/tools-api · github.com/codex-team/editor.js
(docs/tools.md, releases)

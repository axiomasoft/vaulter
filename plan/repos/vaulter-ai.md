# Репозиторий `vaulter-ai` (Офисный AI SDK, чат и исполнители)

- **Git-репозиторий:** `axiomasoft/vaulter-ai` (создан: `https://github.com/axiomasoft/vaulter-ai`, локально `/home/vostrikov/projects/packages/vaulter-ai`; пишется с нуля по контрактам `plan/spec/agent-sdk.md` и `plan/spec/implementation/ai.md`)
- **Канал поставки:** Private / Proprietary (`FlexCrm`, `Edufy`)
- **План разработки:** [dev-plans/vaulter-ai.md](../dev-plans/vaulter-ai.md)

## Что располагается в репозитории

| Путь | Пакет | Ответственность |
| --- | --- | --- |
| `packages/core/` | `axiomasoft/vaulter-ai-core` | Ядро Office SDK v1 (`POST /api/vaulter-ai/v1/operations`): 7 операций (`search/read/describe/prepare/commit/status/verify`), JSON Schema validator, собственный RFC 8785 JCS `ProposalDigest`, хранилище P/Q (`v_ai_proposals`, `v_ai_owner_routes`, `v_ai_dispatches`, `v_ai_runs`, `v_ai_run_steps`), **возобновляемый AI-чат SSE** (`v_ai_chat_sessions`, `v_ai_chat_messages`, `v_ai_chat_events`, `v_ai_tool_calls`, `Last-Event-ID`) |
| `packages/documents/` | `axiomasoft/vaulter-ai-documents` | Текстовый адаптер одиночных документов: `TextReader`, `IndexText` (проекции), `ReplaceExact`, `SingleTextOwner` (через `AtomicDocumentMutation`), `TextVerifier`, миграция `v_ai_text_commands` |
| `packages/sheets/` | `axiomasoft/vaulter-ai-sheets` | Табличный адаптер: `SheetReader`, `FillFormula` (`fixed_members` / `current_rows`), `SheetCommandOwner` (через `WorkbookOperations`). Собственных миграций нет |
| `packages/collaboration/` | `axiomasoft/vaulter-ai-collaboration` | Адаптер живого текста (`3.5`): `LiveTextCommandOwner`, `LiveTextReader` поверх `vaulter-collaboration` `CommandJournal`. Собственных миграций нет |
| `packages/laravel-ai/` | `axiomasoft/vaulter-ai-laravel-ai` | Первый исполнитель: `OfficeAgent`, `OfficeTool`, `LaravelOfficeExecutor` (Laravel AI, возобновление шагов по `status`, streaming) |
| `packages/agentools/` | `axiomasoft/vaulter-ai-agentools` | Опциональный исполнитель: `OfficeToolPort`, `OfficeToolDescriptorRegistry`, `ActionKeyMap` поверх `agentools/sdk` |
| `packages/vue/` | `@vaulter/ai-vue` | UI-панель согласования P/Q, diff/квитанции и панель возобновляемого AI-чата (SSE, курсор, выделение редактора) |
| `clients/python/` | `vaulter-office-client` | Тонкий stdlib Python HTTP-клиент (`OfficeClient.call`, `resume`) без DSN/ORM |
| `tests/consumers/office/` | — | Общий Docker Compose стенд (`stack.sh`, `profiles.json`) для приёмки профилей `ai-text`, `ai-sheets`, `ai-live`, `ai-agentools` |

## Что запрещено в `vaulter-ai`

- Прямое редактирование таблиц Documents/Collaboration/Sheets в обход портов владельцев.
- Передача секретов LLM-провайдеров в браузер, события SSE или логи.
- Использование `mmccook/php-json-canonicalization-scheme` 1.0.0 или `ContentHasher` для `ProposalDigest` (обязателен собственный RFC 8785 JCS).
- Второй реестр моделей или биллинг (это зона хоста / Laravel AI / AgenTools).

## Зависимости

- **Входящие:** `vaulter` (обязательно); опционально по профилю — `vaulter-collaboration` (для `packages/collaboration`), `vaulter-sheets` (для `packages/sheets`).
- **Исходящие артефакты:** 6 Composer ZIP, `@vaulter/ai-vue` tgz, Python wheel/sdist.

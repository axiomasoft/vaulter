# План разработки репозитория `vaulter-ai` (Офисный AI SDK, чат и исполнители)

**Паспорт:** [repos/vaulter-ai.md](../repos/vaulter-ai.md) · **Контракт SDK:** [spec/agent-sdk.md](../spec/agent-sdk.md) · **Спецификация:** [spec/implementation/ai.md](../spec/implementation/ai.md) · **Схема БД:** [ai-core.sql](../spec/database/sql/ai-core.sql) + [ai-documents.sql](../spec/database/sql/ai-documents.sql) + [ai-chat.sql](../spec/database/sql/ai-chat.sql)

Новый закрытый репозиторий, разрабатываемый с нуля. Содержит ядро Office SDK v1, три доменных адаптера (`documents`, `sheets`, `collaboration`), два исполнителя (`laravel-ai`, `agentools`), UI-панель (`@vaulter/ai-vue`), тонкий Python-клиент и общий Docker-стенд `tests/consumers/office/`.

## Очередь задач репозитория

| Шаг | ID | Волна | Зависимости | Исполнитель | Ревьюер | Сдаваемый артефакт |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | `3.2` | W3 | `0.2`, `3.1` (`vaulter`) | **Gemini Neon** | GPT-6.2 Sol | `packages/core` (wire/registry/search/read/describe), `packages/documents` (TextReader, IndexText), `clients/python` скелет |
| 2 | `3.3` | W4 | `3.2` + `AtomicDocumentMutation` | **GPT-6.2 Sol** | **Astra** | `ProposalDigest` (RFC 8785 JCS), `ReplaceExact`, `SingleTextOwner`, `TextVerifier`, `Proposals`/`OwnerRoutes`/`ResolveProposal`, `v_ai_text_commands`, PHP/HTTP/Python parity |
| 3 | `3.4` | W5 | `3.3` | **Gemini Neon** | GPT-6.2 Sol | `packages/laravel-ai` (`OfficeAgent`, `OfficeTool`, `LaravelOfficeExecutor`), `packages/agentools` (`OfficeToolPort`, `OfficeToolDescriptorRegistry`, `ActionKeyMap`), `tests/consumers/office/` |
| 4 | `3.5` (адаптер AI) | W5 | `2.2` (`vaulter-collaboration`), `3.3` | **GPT-6.2 Sol** | **Astra** | `packages/collaboration` (`LiveTextCommandOwner`, `LiveTextReader`), сквозная приёмка A42 с `vaulter-collaboration` |
| 5 | `5.1` | W5 | `3.3`, `4.2` (`vaulter-sheets`) | **Gemini Neon** | GPT-6.2 Sol | `packages/sheets` (`SheetReader`, `FillFormula`, `SheetCommandOwner`), `fixed_members` и `current_rows` |
| 6 | `5.2` | W6 | `3.4`, `5.1` | **Gemini Carbon** | Gemini Neon | `bench/office/{compare.php,workload.json,README.md}`, сравнение 1×/10× (SQL, байты, p95, токены) |
| 7 | `7.1` | W6 | `3.4`, `5.1` | **Gemini Neon** | **Astra** | P/Q согласование, многошаговые `Runs` с частичным результатом, UI-панель review в `packages/vue` (`@vaulter/ai-vue`) |
| 8 | `7.2` | W7 | `7.1` | **Gemini Carbon** | Gemini Neon | Завершённый `clients/python/vaulter_office/client.py`, эмулятор внешнего канала (`FinalMessageTest.php`) |
| 9 | `9.1` | W7 | `3.3`, `7.1` | **GPT-6.2 Sol** | **Astra** | Расширение `packages/core`: миграции `ai-chat.sql`, `POST /office/chat/sessions/{id}/messages`, SSE `GET …/events` (`Last-Event-ID`, `last_seq`), tool call ledger с lease/fencing |
| 10 | `9.2` | W7 | `3.4`, `7.2`, `9.1` | **Gemini Neon** | GPT-6.2 Sol | Чат-панель в `packages/vue` (`@vaulter/ai-vue`), потоковый Laravel AI / AgenTools, привязка внешнего канала к `run_id` |

## Детальные инструкции по шагам

### Шаг 1 (`3.2`) — Gemini Neon → ревью GPT-6.2 Sol
- Инициализировать репозиторий `vaulter-ai` (можно предварительно выполнить scaffold через Gemini Argon): корневой `composer.json` с path-репозиториями `packages/*`, `phpunit.xml.dist`, `phpstan.neon.dist`, `tests/Support/OfficeTestCase.php`.
- Скопировать `plan/spec/schemas/office-v1.schema.json` → `packages/core/resources/contracts/office-v1.schema.json` и `plan/examples/office-sdk.json` → `tests/Fixtures/office-sdk.json`, зафиксировать SHA-256 в `tests/Fixtures/provenance.json`.
- Реализовать шаги `3.2a`–`3.2c`: `OfficeController` (`POST /api/vaulter-ai/v1/operations`), `WireValidator` (`opis/json-schema`), `OfficeRegistry`, `Search`, `Read`, `Describe`, `SearchPages`, `ReadTokens`, `000700_create_office_core_tables.php`, а в `packages/documents` — `TextReader`, `IndexText`, `TextProjectionReader`, `RebuildTextIndex`, `000701_create_ai_documents_tables.php`.
- **Проверка:** `WireContractTest` (все 68 сообщений), `RegistryTest`, `SearchPageTest`, `TextReadAclTest`, `TextProjectionTest`, 3 границы HTTP (400 `invalid_request` / `unsupported_version` / `invalid_input`).

### Шаг 2 (`3.3`) — GPT-6.2 Sol → ревью Astra
- Реализовать шаги `3.3a`–`3.3e`:
  - `ProposalDigest` — собственный RFC 8785 JCS (UTF-16 сортировка ключей, safe integers, сверка по 16 векторам `plan/examples/reference/proposal-digest/vectors.json`; `mmccook` 1.0.0 и `ContentHasher` запрещены).
  - `ReplaceExact` — UTF-16 смещения, объединение run, перекрывающиеся совпадения, сверка по `plan/examples/reference/text-replace/vectors.json`.
  - `Proposals`, `OwnerRoutes` (commit #1 до вызова владельца), `ResolveProposal`, `DispatchApprovedCommand`.
  - `SingleTextOwner` поверх `AtomicDocumentMutation::run` (commit #2: сначала проверка ключа/proposal_id, затем срок/режим/поколение/схема/цель, затем replace + `v_ai_text_commands` + evidence + outbox), `TextVerifier` (`at_commit` / `current`).
- **Проверка:** `ProposalDecisionRaceTest`, `OwnerTransactionTest`, `CommandRouteTest`, `RecoveryTest`, 4 сценария (`text_success`, `text_conflict`, `lost_response`, `acl_revoked`) через PHP, HTTP и Python.

### Шаг 3 (`3.4`) — Gemini Neon → ревью GPT-6.2 Sol
- Реализовать `packages/laravel-ai` (`OfficeAgent`, `OfficeTool`, `LaravelOfficeExecutor`) с возобновлением по `office.status` при рестарте worker (новый `toolCallId` не создаёт дубль записи).
- Реализовать `packages/agentools` (`OfficeToolPort`, `OfficeToolDescriptorRegistry`, `ActionKeyMap`) поверх `agentools/sdk`.
- Поднять общий стенд `tests/consumers/office/` (`compose.yaml`, `stack.sh`, `profiles.json`, Dockerfiles для php/browser/python) по `plan/spec/implementation/processes.md`.
- **Проверка:** `AgentFlowTest` (с SIGKILL worker), `ConfirmedWriteTest`, `InstallMatrixTest` (работа при отключённом AgenTools), отдельный smoke с реальным ключом.

### Шаг 4 (`3.5` адаптер AI) — GPT-6.2 Sol → ревью Astra
- Создать `packages/collaboration` (`LiveTextCommandOwner`, `LiveTextReader`), маршрутизировать `write_mode=journal` к `vaulter-collaboration` `CommandJournal` только при включённой и проверенной capability.
- **Проверка:** `LiveRoutingTest`, `RecoveryAfterCommitTest`, `DelayedDeliveryTest`, совместный прогон 11 вариантов A42 с `vaulter-collaboration`.

### Шаг 5 (`5.1`) — Gemini Neon → ревью GPT-6.2 Sol
- Создать `packages/sheets` (`SheetReader`, `FillFormula`, `SheetCommandOwner`) поверх `Vaulter\Sheets\Contracts\WorkbookOperations` из `vaulter-sheets`.
- Поддержать `fixed_members` (D2/D4 = 20/−20, D3 пуста, D5 = 77) и `current_rows` (`scope_changed` при вставке строки), 8 негативов `adapter_negatives`.
- **Проверка:** `FixedMembersTest`, `CurrentRowsTest`, `CalculationTest`, `SheetAclTest` на PHP и HTTP + переоткрытие в браузере.

### Шаг 6 (`5.2`) — Gemini Carbon → ревью Gemini Neon
- Создать `bench/office/{compare.php,workload.json,README.md}`, измерить полный снимок vs SDK на корпусе 1× и 10× (холодный/прогретый p95, SQL, буферы, HTTP-байты, вызовы модели, токены, lag индекса).

### Шаг 7 (`7.1`) — Gemini Neon → ревью Astra
- Реализовать маршруты `GET /office/proposals/{id}`, `POST /office/proposals/{id}/decision` (lock P → проверка `clock_timestamp()` и прав → CAS `pending`), `GET /office/runs/{id}` и многошаговые `Runs` (частичный успех двух ресурсов).
- Создать `packages/vue` (`@vaulter/ai-vue`) с панелью P/Q (инициатор, diff, digest, раздельные статусы «принято человеком» / «сохранено владельцем» / «проверено»).
- **Проверка:** `ProposalDecisionTest` (гонка approve/reject P, независимый Q, потеря прав до commit, отзыв View до чтения, рестарт после commit).

### Шаг 8 (`7.2`) — Gemini Carbon → ревью Gemini Neon
- Завершить `clients/python/vaulter_office/client.py` (stdlib, без DSN/ORM, `resume()` через `status` после потери ответа) и `FinalMessageTest.php` (эмулятор канала: partial = 0 runs, final + дубликат = 1 run, reconnect = чтение исхода по ACL).

### Шаг 9 (`9.1`) — GPT-6.2 Sol → ревью Astra
- Добавить миграцию `0001_01_01_000702_create_office_chat_tables.php` по `plan/spec/database/sql/ai-chat.sql`.
- Реализовать `POST /office/chat/sessions/{id}/messages` (уникальный `message_id` → один `run_id`), транзакционный инкремент `v_ai_chat_sessions.last_seq` для `v_ai_chat_events`, SSE `GET /office/chat/sessions/{id}/events` с `Last-Event-ID` и повторным ACL по `resource_scope_json`.
- Реализовать `v_ai_tool_calls` ledger с lease/fencing, валидацией полного JSON tool input и восстановлением через `status`/`verify` после SIGKILL worker.
- **Проверка:** детерминированный тест `CHAT` (обрыв SSE, SIGKILL worker после commit, 2 транзакции одной сессии + чужая сессия, отзыв прав).

### Шаг 10 (`9.2`) — Gemini Neon → ревью GPT-6.2 Sol
- Добавить в `@vaulter/ai-vue` панель чата с SSE-курсором, передачей ограниченного выделения редактора, раздельными статусами (`assistant.interrupted`, `waiting approval`, `owner committed`, `verified`) и скрытием фрагментов при отзыве прав.
- Подключить streaming и каталог tools в `packages/laravel-ai` и `packages/agentools`, связать внешний канал 7.2 с сессией чата.
- **Проверка:** `CHAT` + `CHANNEL` в двух браузерных контекстах и при закрытых вкладках + отдельный smoke с реальной моделью.

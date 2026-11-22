# План разработки репозитория `vaulter-sheets` (Электронные книги)

**Паспорт:** [repos/vaulter-sheets.md](../repos/vaulter-sheets.md) · **Спецификация:** [spec/implementation/sheets.md](../spec/implementation/sheets.md) · **Wire-контракт:** [spec/implementation/sheets-runtime-wire.md](../spec/implementation/sheets-runtime-wire.md) · **Схема БД:** [spec/database/sheets.md](../spec/database/sheets.md)

Новый закрытый репозиторий. Начинается с чистого переноса существующего `SpreadsheetDocumentType` и `VaulterSpreadsheet` (`1.1`), выбора движка (`4.1`), создания нативной книги с Node runtime (`4.2`), опционального обмена XLSX/CSV (`4.3`) и независимых расширений (`6.1` realtime, `6.2` Tiptap link).

## Очередь задач репозитория

| Шаг | ID | Волна | Зависимости | Исполнитель | Ревьюер | Сдаваемый артефакт |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | `1.1` | W1 | `0.1` (`vaulter`) | **Gemini Neon** | GPT-6.2 Sol | `packages/sheets` + `packages/vue` с перенесённым типом `vaulter/spreadsheet` и `VaulterSpreadsheet`, пара архивов, `package-consumers.sh` |
| 2 | `4.1` | W1 | `0.2` (`vaulter`) | **Gemini Carbon** | Gemini Neon | `bench/engines/{univer,ironcalc,handsontable-hyperformula}`, `bench/results/`, утверждённый `profile.ts` |
| 3 | `4.2` | W3 | `1.0`, `1.1`, `4.1` + `AtomicDocumentMutation` | **GPT-6.2 Sol** | **Astra** | `WorkbookCodec` v2, `WorkbookOperations`, `CommitWorkbookCommand`, миграции `0006NN`, `packages/runtime` (CLI + HTTP 3100 + Dockerfile), обновлённый `@vaulter/sheets-vue`, `workbook-contract.sh` + `workbook-browser.sh` |
| 4 | `4.3` | W4 | `4.2` (условная по профилю) | **Gemini Neon** | Gemini Carbon | `WorkbookExchange`, `RunWorkbookExchange`, `v_sheets_exchange_jobs`, `POST /v1/exchange` (CSV/XLSX), `exchange.sh` |
| 5 | `6.1` | W4 | `4.2` | **GPT-6.2 Sol** | **Astra** | `packages/realtime` (порт 3101 + Dockerfile), `WorkbookRoomSession`, `AppendWorkbookMutation`, `v_sheets_realtime_*`, `realtime.sh` |
| 6 | `6.2` | W4 | `4.2` | **Gemini Carbon** | Gemini Neon | `packages/tiptap` (`@vaulter/sheets-tiptap`), `WorkbookReference`, `WorkbookPreview.vue`, `reference.sh` |

## Детальные инструкции по шагам

### Шаг 1 (`1.1`) — Gemini Neon → ревью GPT-6.2 Sol
- Инициализировать репозиторий `vaulter-sheets` (scaffold: `composer.json`, `package.json` workspaces, `contracts/`, `scripts/ci/`).
- Перенести из `vaulter`:
  - `SpreadsheetDocumentType.php` → `packages/sheets/src/Types/SpreadsheetDocumentType.php` (`Vaulter\Sheets\Types`), создать `VaulterSheetsServiceProvider.php` и `SheetsModule.php` (вклад в слот `DocumentType::class` с ключом `vaulter/spreadsheet`).
  - `VaulterSpreadsheet.vue` → `packages/vue/src/components/VaulterSpreadsheet.vue` (импорты только из публичного `@vaulter/vue`).
  - `SpreadsheetContentTest.php`, `spreadsheet.test.ts`, плюс `SpreadsheetTypeTest.php` с вызовом `DocumentTypeContractTests`.
- **Проверка:** `scripts/ci/package-consumers.sh` — сборка ZIP/tgz и установка в чистый потребитель поверх `vaulter`, CREATE→GET→PUT→GET и 412 при старом ETag.

### Шаг 2 (`4.1`) — Gemini Carbon → ревью Gemini Neon
- Выполнить сравнение на едином корпусе (`examples/workbook.json` + 100k ячеек / 10k формул): Univer 1.5.2 (browser + Node), IronCalc 0.9.1, Handsontable 18.1.2 + HyperFormula 3.5.0.
- Зафиксировать в `bench/results/` формулы (35 → 41, вставка строки `=SUM(Detail!D3:D4)`, холодное открытие с ложным кэшем 999 → 41), ресурсы (conditional formatting, unknown resource), p50/p95, long tasks и память после 20 open/dispose.
- **Decision Gate 4.1:** утвердить `packages/runtime/src/profile.ts` (список плагинов, версий и запрещённых операций для уязвимых ресурсов). Без этого gate `4.2` не стартует.

### Шаг 3 (`4.2`) — GPT-6.2 Sol → ревью Astra
- **Схемы и контракты:** создать `contracts/{runtime-v1.schema.json,workbook-v2.schema.json,http-v1.yaml}`.
- **PHP (`packages/sheets`):**
  - Миграции `0001_01_01_0006NN` по `plan/spec/database/sql/{sheets,sheets-projections}.sql`.
  - `WorkbookCodec` (schema v2), `RequiresCommandWrite` (generic PUT → `409 document_command_required`).
  - `WorkbookOperations` + `LaravelWorkbookOperations` (`catalog`, `readTable`, `prepareFormula`, `commit`, `status`, `verify`), `CommitWorkbookCommand` поверх `AtomicDocumentMutation` (политики `exact_base`, `rebase_cells`, `revalidate_by_id` — образец `plan/examples/reference/workbook-operation/`).
  - `NodeWorkbookRuntime` (CLI) и `HttpWorkbookRuntime` (HTTP bearer, порт 3100), очереди `sheets-commands` (`retry_after=75s`, lease 60s), `RecoverWorkbookJobs` (`vaulter-sheets:recover`).
  - `PublishCellProjection` и `ReadWorkbookRange` (задел для `5.1`).
- **Node Runtime (`packages/runtime`):** `cli.ts`, `server.ts` (`/v1/operations`, `/livez`, `/readyz`), `protocol.ts`, `operation.ts`, `table-bindings.ts` (владелец выдаёт `new_row_ids`), `engine/univer.ts`, `browser.ts`, `Dockerfile`.
- **Vue (`packages/vue`):** `VaulterSpreadsheet.vue` с ленивой загрузкой движка, `mountWorkbook.ts`, `useWorkbook.ts`, нормализация UI-правок в команды, отключение неподдерживаемых операций в UI.
- **Проверка:** `scripts/ci/workbook-contract.sh` и `scripts/ci/workbook-browser.sh` (PostgreSQL 18 + HTTP runtime + Playwright), холодное переоткрытие после kill Node и вкладки.

### Шаг 4 (`4.3`) — Gemini Neon → ревью Gemini Carbon
- Запускается, если профиль поставки заказывает `csv` и/или `xlsx` (указывается в `exchange_formats` отчёта).
- Реализовать `WorkbookExchange.php`, `RunWorkbookExchange.php`, `WorkbookExchangeController.php`, миграцию `sheets-exchange.sql`, `packages/runtime/src/exchange/{csv,xlsx,worker}.ts` (`POST /v1/exchange`).
- Оригинал XLSX удерживается в Media, экспорт привязан к конкретному маркеру/ревизии, лимиты распаковки проверяются до аллокации, для отключённого формата проверяется отсутствие capability (`disabled`).
- **Проверка:** `scripts/ci/exchange.sh`.

### Шаг 5 (`6.1`) — GPT-6.2 Sol → ревью Astra
- **Decision Gate 6.1:** до старта кода зафиксировать выбор: собственный журнал типизированных команд Sheets (рекомендуется для полной независимости и отсутствия лицензии Pro) либо коммерческий Univer Pro под нашим fence/receipt.
- Реализовать `packages/realtime/` (`server.ts` на 3101, `native-adapter.ts`, `client.ts`, `replay.ts`, `Dockerfile`) и PHP `WorkbookRoomSession.php`, `AppendWorkbookMutation.php`, миграцию `sheets-realtime.sql`.
- Использовать `CollaborativeState` из `vaulter/packages/documents`, переходы `snapshot ↔ journal` с повышением `generation`.
- **Проверка:** `scripts/ci/realtime.sh` — гонка «вставка строки + изменение прежней C3» в обоих порядках, потеря ответа, смена лидера, restore; после `5.1` повторить через AI SDK.

### Шаг 6 (`6.2`) — Gemini Carbon → ревью Gemini Neon
- Создать `packages/tiptap/` (`@vaulter/sheets-tiptap`): `WorkbookReference.ts` (обычный link mark с URL `/office/workbooks/{nodeId}/sheets/{sheetId}?range=D2%3AD4`), `WorkbookPreview.vue`.
- Сервер повторно проверяет ACL книги при раскрытии и при экспорте; без расширения базовый редактор сохраняет обычную ссылку.
- **Проверка:** `scripts/ci/reference.sh` (читатель без доступа к книге не видит значений/названия; удалённый лист → нейтральная заглушка).

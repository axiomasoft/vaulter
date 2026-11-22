# План реализации экосистемы Vaulter

**Корневая папка плана — `plan/`.** Экосистема распределена по **5 репозиториям** ([карта репозиториев](repos/README.md)): существующей публичной базе `vaulter` и четырём закрытым репозиториям (`vaulter-collaboration`, `vaulter-sheets`, `vaulter-ai`, `vaulter-editor-pro`). В `phases/` находятся карточки работ (`00–10`), в `dev-plans/` — автономные планы разработки для каждого репозитория, в `execution/` — матрица моделей-исполнителей и волн, в `spec/` — спецификации, в `examples/` — эталоны и в `checks/` — исполняемые проверки. Начните с карточки [0.1](phases/00-baseline.md) и [аудита плана](audit-report.md). Исходная ревизия Vaulter — `b230f6e27`. Пакеты проектируются для чистой установки.

| Репозиторий | Поставка и назначение | Паспорт | План разработки |
| --- | --- | --- | --- |
| `vaulter` | Чистая публичная база для FlexCms: Core, Media, Drive, Documents (`write_mode`, `blockId`, `AtomicDocumentMutation`), публичный `@vaulter/vue` и одиночный Tiptap | [repos/vaulter.md](repos/vaulter.md) | [dev-plans/vaulter.md](dev-plans/vaulter.md) |
| `vaulter-collaboration` | Закрытая совместная работа текста (вынос из `vaulter`): PHP-журнал, `@vaulter/vue-collaboration`, Hocuspocus Hub, strict live-write A42 | [repos/vaulter-collaboration.md](repos/vaulter-collaboration.md) | [dev-plans/vaulter-collaboration.md](dev-plans/vaulter-collaboration.md) |
| `vaulter-sheets` | Закрытые электронные книги: PHP Sheets, `@vaulter/sheets-vue`, Node runtime, XLSX/CSV, realtime и Tiptap `WorkbookReference` | [repos/vaulter-sheets.md](repos/vaulter-sheets.md) | [dev-plans/vaulter-sheets.md](dev-plans/vaulter-sheets.md) |
| `vaulter-ai` | Закрытый Office SDK v1, адаптеры (documents/sheets/collaboration), P/Q согласование, возобновляемый AI-чат SSE, Laravel AI, AgenTools, Python-клиент | [repos/vaulter-ai.md](repos/vaulter-ai.md) | [dev-plans/vaulter-ai.md](dev-plans/vaulter-ai.md) |
| `vaulter-editor-pro` | Закрытая собственная замена Tiptap Pro: History Preview/Compare, Range Comments, Tracked Changes / Structural Review, DOCX import/export | [repos/vaulter-editor-pro.md](repos/vaulter-editor-pro.md) | [dev-plans/vaulter-editor-pro.md](dev-plans/vaulter-editor-pro.md) |

Долгосрочный ориентир — офис уровня Univer.ai для документов и книг без зависимости от Tiptap Pro/Cloud. Первый сквозной результат — агент по поручению пользователя меняет условие договора или заполняет выбранные строки формулами и возвращает проверяемую квитанцию. Приёмка проходит в изолированных потребителях. Подключение к продуктовым приложениям (FlexCms, FlexCrm, Edufy) относится к последующим работам.

## Фазы (`00–10`)

Работать по одной задаче согласно [волнам и матрице исполнителей](execution/models-and-orchestration.md). В каждой карточке указаны предшественники, порядок действий, карта файлов и идентификаторы проверок.

| Фаза | Сдаваемый результат |
| --- | --- |
| [0. Исходное состояние](phases/00-baseline.md) | Установка из архивов, контрольные сценарии и исходная схема БД |
| [1. Документы](phases/01-documents.md) | Универсальная голова документа (`write_mode`) и типизированные данные, перенос пакета книги, чистый базовый редактор |
| [2. Коллаборация](phases/02-collaboration.md) | Вынос `vaulter-collaboration`, исправление `protocol=2`, два пишущих браузера, compaction lease, рестарт и восстановление поколения |
| [3. Текстовый SDK](phases/03-text-sdk.md) | Адресуемые блоки `blockId`, API агента, квитанции, исполнители и отдельно доказанная запись в живую комнату (A42) |
| [4. Книги](phases/04-sheets.md) | Выбранный профиль движка, закрытый редактор и вычислитель. Для XLSX и CSV предусмотрены независимые проверки при включении |
| [5. SDK для книг](phases/05-sheet-sdk.md) | Запись формул в выбранные строки и измеренная стоимость чтения |
| [6. Дополнения](phases/06-extensions.md) | Независимые возможности совместной книги и ссылки на диапазон в Tiptap |
| [7. Поручение](phases/07-agent-workspace.md) | Согласование P/Q, возобновление задания и проверка внешнего канала на эмуляторе |
| [8. Editor Pro: Review и Comments](phases/08-editor-pro-review.md) | History Preview/Compare, Range Comments (Yjs/UTF-16 anchors), текстовый и структурный Tracked Changes |
| [9. Возобновляемый AI-чат](phases/09-ai-chat.md) | Backend чата с SSE `Last-Event-ID`, tool call ledger, UI панели чата и потоковые исполнители |
| [10. DOCX и обмен документами](phases/10-docx-exchange.md) | Корпус OOXML (D1–D4), локальный офлайн-экспорт и серверные `document_exchange_jobs` с атомарным импортом |

## Структура

| Где | Что является источником решения |
| --- | --- |
| [repos/README.md](repos/README.md) | Короткие паспорта всех 5 репозиториев: имена, пакеты, границы и зависимости |
| [dev-plans/](dev-plans/vaulter.md) | Автономные планы разработки для каждого из 5 репозиториев |
| [execution/models-and-orchestration.md](execution/models-and-orchestration.md) | Назначение моделей (**Astra**, **GPT-6.2 Sol**, **Gemini Neon**, **Gemini Carbon**, **Gemini Argon**), волны W0–W7, handoff и порядок ревью |
| [audit-report.md](audit-report.md) | Реестр найденных и закрытых дыр плана |
| [spec/implementation/vaulter.md](spec/implementation/vaulter.md) | Существующие PHP/Vue/Hub файлы, изменения и владельцы, переносы и удаления |
| [spec/implementation/sheets.md](spec/implementation/sheets.md) | Целевое дерево `vaulter-sheets`, PHP/Node/Vue, realtime, ссылки и XLSX |
| [spec/implementation/ai.md](spec/implementation/ai.md) | Целевое дерево `vaulter-ai`, порты, очереди, возобновляемый AI-чат SSE, Laravel AI, AgenTools, Python |
| [spec/implementation/editor-pro.md](spec/implementation/editor-pro.md) | Синтезированная спецификация `vaulter-editor-pro` (из `tiptap-pro/`): Range Comments, Compare, Tracked Changes, DOCX и диспозиция всех 93 функций Tiptap Pro |
| [spec/implementation/workbook-flow.md](spec/implementation/workbook-flow.md) | Одна правка Excel-подобной книги от ввода в ячейку до SQL commit, пересчёта, чтения агентом и восстановления |
| [spec/implementation/hosts.md](spec/implementation/hosts.md) | Файлы изолированных потребителей и выбор комплектации |
| [spec/implementation/processes.md](spec/implementation/processes.md) | Dockerfile, Compose-профили, сетевые входы, тома, миграции, readiness и порядок останова |
| [spec/database/](spec/database/README.md) | ER, владельцы таблиц, поля, ограничения, индексы и исполняемый SQL по пакетам (включая `ai-chat.sql`, `editor-pro-review.sql`, `editor-pro-exchange.sql`) |
| [spec/agent-sdk.md](spec/agent-sdk.md) и [spec/schemas/](spec/schemas/office-v1.schema.json) | API v1, JSON Schema, ошибки и повторы |
| [spec/collaboration.md](spec/collaboration.md#предлагаемый-протокол-для-одного-документа) | Запись агента в строгой комнате, порядок браузерных изменений, восстановление и условия включения по задаче 3.5 |
| [tiptap-pro/README.md](tiptap-pro/README.md) | Исследование Tiptap Pro/Cloud, технический проект и матрица приёмки для фаз 08–10 |
| [examples/office-sdk.json](examples/office-sdk.json), [workbook.json](examples/workbook.json), [live-write.json](examples/live-write.json) | Эталонные запросы/ответы, команды книги и аварии журнала |
| [acceptance.md](acceptance.md) | Порядок сдачи одной задачи по её ID, шаблон результата и команда проверки |

Проверки плана, примеров и SQL запускаются из корня Vaulter.

```bash
python3 plan/checks/verify-plan.py
python3 plan/checks/verify-fixtures.py
PG_BIN=/usr/lib/postgresql/18/bin bash plan/checks/schema.sh
```

# Репозиторий `vaulter-sheets` (Электронные книги)

- **Git-репозиторий:** `axiomasoft/vaulter-sheets` (`https://github.com/axiomasoft/vaulter-sheets`, приватный, клонирован в `/home/vostrikov/projects/packages/vaulter-sheets`)
- **Канал поставки:** Private / Proprietary (`FlexCrm`, `Edufy`)
- **План разработки:** [dev-plans/vaulter-sheets.md](../dev-plans/vaulter-sheets.md)

## Что располагается в репозитории

| Путь | Пакет / Образ | Ответственность |
| --- | --- | --- |
| `packages/sheets/` | `axiomasoft/vaulter-sheets` | PHP-владелец книг: `SpreadsheetDocumentType` (`vaulter/spreadsheet`, `RequiresCommandWrite`), `WorkbookCodec` v2, `WorkbookOperations` (единый порт для UI и AI), `CommitWorkbookCommand`, миграции `v_sheets_*`, проекции ячеек, очереди `sheets-commands` / `sheets-exchange`, REST API `/api/vaulter-sheets/v1` |
| `packages/vue/` | `@vaulter/sheets-vue` | Закрытый Vue viewer `VaulterSpreadsheet` (регистрируется на `vaulter/univer`), ленивая загрузка движка, `mountWorkbook`, `useWorkbook`, преобразование правок в типизированные команды |
| `packages/runtime/` | `@vaulter/sheets-runtime` (+ Docker образ, порт 3100) | Node CLI и приватный HTTP-вычислитель (`POST /v1/operations`, `POST /v1/exchange`): адаптер **Univer OSS (Apache-2.0)**, `table-bindings.ts` (устойчивые row/column IDs), проверка профиля ресурсов, Zero-Pro CSV/XLSX конвертер (IronCalc / MIT OOXML) |
| `packages/realtime/` | `@vaulter/sheets-realtime` (+ Docker образ, порт 3101) | Независимое дополнение `6.1`: собственный Zero-Pro сервер и клиент совместной книги (`univer-sheets/1`) поверх Apache-2.0 ядра Univer и **единого PHP-журнала `vaulter-collaboration`**, WebSocket `/v1/rooms/{roomId}` |
| `packages/tiptap/` | `@vaulter/sheets-tiptap` | Независимое дополнение `6.2`: расширение Tiptap `WorkbookReference` (обычный link mark + ACL-проверяемое превью диапазона) |
| `contracts/` | — | Канон схем: `runtime-v1.schema.json`, `workbook-v2.schema.json`, `exchange-v1.schema.json`, `http-v1.yaml` |
| `bench/` и `tests/` | — | Сравнение движков `4.1` (Univer OSS Apache-2.0 + IronCalc MIT/Apache-2.0; Handsontable/HyperFormula — только офлайн-ориентир бенчмарка, запрещены в production), фикстуры `workbook.json` / XLSX, изолированный consumer + Playwright E2E |

## Что запрещено в `vaulter-sheets`

- Любые коммерческие и копилефт-пакеты в production (`@univerjs-pro/*`, `handsontable`, `hyperformula`, GPL/AGPL) — только Apache-2.0 / MIT / BSD / ISC.
- Дублирование таблиц журнала совместной работы (в `6.1` используется единый PHP-журнал `vaulter-collaboration` + тонкая таблица связей `v_sheets_realtime_links`).
- Прямое обращение Node runtime к PostgreSQL или хранение ключей пользователей/LLM в Node.
- Второй канонический снимок книги в обход `v_document_payloads` / `vaulter-media` CAS manifest / `AtomicDocumentMutation`.
- Использование текстового Yjs/Hocuspocus для структурных операций книги.
- Логика AI-агентов и каталога Office SDK (живёт в `vaulter-ai/packages/sheets`).

## Зависимости

- **Входящие:** артефакты `vaulter` (`axiomasoft/vaulter-core`, `media`, `drive`, `documents` с `AtomicDocumentMutation` и `RequiresCommandWrite`, `@vaulter/vue`); опционально для `6.1` — `axiomasoft/vaulter-collaboration` (единый протокольно-нейтральный PHP-журнал).
- **Исходящие артефакты:** `axiomasoft/vaulter-sheets`, `@vaulter/sheets-vue`, `@vaulter/sheets-runtime` (+ Docker), опционально `@vaulter/sheets-realtime`, `@vaulter/sheets-tiptap` (используются `vaulter-ai` в `5.1`).

# Участок 3: книга проходит путь Vue → PHP-владелец → вычислитель Node (журнал исполнителя)

Остановлено по указанию координатора 2026-11-20 22:22. Spec, фазы и фикстуры **не менялись**, недоделанных правок в общих файлах нет. Готовы образец, проверка и JSON Schema.

## Факты (прочитано и измерено)
- `plan/spec/implementation/sheets.md` §«Внутренний обмен PHP и Node». Поля, digest и коды описаны прозой внутри общего документа. В нём нет списка кодов ошибок, нет схемы команды по ID для 5.1 (`fill_formula`) и нет формата `tables`. Пример `"expected":5` не различает число, текст, формулу и пустую ячейку.
- `plan/checks/sheets-headless/edge-cases.mjs cached-value`: после открытия снимка `getRawValue()` возвращает 999, пока не вызван `executeCalculation()`.
- Univer 1.5.2, `.d.ts`:
  - `FFormula.onCalculationResultApplied(timeout?)` завершается ошибкой по тайм-ауту (`engine-formula/lib/types/facade/f-formula.d.ts:136`);
  - `FWorksheet.getRange(row, column)` и `insertRowsBefore(beforePosition, howMany)` нумеруют с нуля;
  - `createWorkbook` принимает собственные id листов.
- Минимальный профиль (core, docs, engine-render, engine-formula, sheets, sheets-formula, sheets-numfmt) пишет в `save().resources` 6 имён. Список зафиксирован в `src/profile.ts` (`PROFILE_RESOURCES`).
- **Новый факт.** `setNumberFormat` создаёт в `native.styles` стиль со случайным ID: два прогона `seed.ts` дали sha256 `0ab223f4…` и `5fb95718…`. При этом apply по одному и тому же снимку без нового стиля даёт одинаковые байты в разных процессах (S6). Вывод: повторный расчёт не обязан воспроизвести digest кандидата. Идемпотентность определяется отпечатком команды и квитанцией, а не сравнением digest нового расчёта. Это нужно записать в wire-spec, и это касается replay в 6.1.

## Подтверждённые противоречия (исправлены в образце, в spec ещё не перенесены)
1. В sheets.md: «отвергать целые вне безопасного диапазона JS». Но `JSON.stringify(1e16)` даёт `10000000000000000`, то есть ячейка со значением 1e16 сделала бы снимок неотправляемым. Принятая норма: целый токен вне ±(2^53−1) принимается, только если он совпадает с ES-печатью полученного double, иначе `invalid_message`. Реализовано одинаково в `src/wire.ts:parseStrict` и `php/Wire.php:StrictJson`. Вектор `[9007199254740993]` отвергнут обеими сторонами, `[10000000000000000,123456789012345680000]` принят обеими.
2. Значение `expected` без тега неоднозначно. В образце вместо него используется теговое `CellContent` (`null | {number} | {text} | {boolean} | {formula}`).
3. Не определено, кто выдаёт row ID при вставке внутри таблицы. В образце владелец передаёт `new_row_ids`, иначе runtime возвращает `identity_required`. Runtime ID не придумывает.
4. Шаг 3 «Атомарной записи» для `fixed_members` разрешает пересчёт по новым координатам, но в протоколе нет такой политики. В образце введены две политики владельца: `exact_base` (If-Match, любое движение головы → `version_conflict`) и `revalidate_by_id` (повторный вызов runtime на новой голове; `current_rows` там получает `scope_changed`).

## Созданные файлы
- `plan/examples/reference/workbook-operation/`:
  - `package.json`, `npm-shrinkwrap.json`: Univer 1.5.2, как в sheets-headless, плюс TypeScript 5.9.3 из корневого package-lock;
  - `tsconfig.json`;
  - `runtime-v1.schema.json`: JSON Schema 2020-12, все 4 примера валидны;
  - `src/wire.ts`: типы, коды ошибок, строгий JSON, RFC 8785, sha256;
  - `src/coordinates.ts`: `engineIndex()`, единственное место перехода к нумерации с нуля;
  - `src/table-bindings.ts`: логические ID → текущие координаты, сдвиг при вставке;
  - `src/profile.ts`;
  - `src/engine/univer.ts`: `UniverWorkbookSession` с методами `open`, `cell`, `insertRowsBefore`, `settle` (барьер), `save`;
  - `src/operation.ts`: `runOperation`, `assertResourcesPreserved`;
  - `src/cli.ts`: stdin/stdout, одна строка;
  - `src/seed.ts`: доверенный импорт корпусов;
  - `src/vue-client.ts`: точка вызова из Vue (`submitWorkbookCommand` и др.);
  - `php/Wire.php`, `php/Owner.php`: `DocumentStatePort` (BOUNDARY для AtomicDocumentMutation), `FileDocumentState`, `ProcessWorkbookRuntime`, `WorkbookOwner::commit/read/status`, `verifyCandidate`, `mapRuntimeError`;
  - `php/owner-cli.php`, `php/http.php` (роутер для `php -S`), `php/canonical-cli.php`;
  - `examples/apply-request.json`, `apply-response.json`, `scope-changed-request.json`, `scope-changed-response.json`.
- `plan/checks/workbook-operation/run.sh`, `check.mjs`, `write-examples.mjs`.

Где показаны требуемые точки:
- точка вызова из Vue: `src/vue-client.ts:submitWorkbookCommand`;
- повторная проверка версии перед публикацией: `php/Owner.php` `WorkbookOwner::commit`, замыкание в `$this->state->locked(...)` сравнивает generation, position и sha256;
- проверка digest владельцем: `WorkbookOwner::verifyCandidate`;
- потеря ресурса до сохранения: `operation.ts`, предварительная проверка `unknown_resource` и `assertResourcesPreserved` (`resource_lost`), обе дают `unsupported_content`; у PHP они переходят в 422 без публикации.

## Команды и фактический вывод
`bash plan/checks/workbook-operation/run.sh` (≈12 с, код 0):
```
node v24.12.0, PHP 8.4.1
OK A0 open via PHP, confirmed total {"formula":"=SUM(Detail!D2:D3)","value":35}
OK A1 Vue set_number C3=7 -> receipt position, D3, total [2,21,41]
OK A2 insert_row_before row 2 -> position 3
OK A3 retry returns original receipt, 0 runtime calls / 0 publications; changed body -> idempotency_conflict
OK A4 stale C3 (old ETag: rejected before runtime; fresh ETag: runtime precondition_failed) -> apples keep {"formula":null,"value":10}
OK A5 cold reopen in a new Node process: D3/D4 formulas, total {"formula":"=SUM(Detail!D3:D4)","value":41}
OK A6 cache 999: runtime barrier -> 41; naive open without barrier -> 999; owner refuses stored bytes with foreign digest
OK A7 lost response recovered by GET status {"generation":1,"position":2}
OK A8 barrier: candidate computed on position 3 is refused after another commit (position 4)
OK B0 human row insertion needs an owner-issued row ID; with it -> position 12
OK B1 fixed_members after insertion: D2/D4 formulas+values, D3 empty, D5 77 ["D2=C2-B2=20","D4=C4-B4=-20"]
OK B2 SDK retry of sheet-1 replays the receipt without a runtime call
OK B3 barrier: position moved during calculation -> owner re-ran runtime, travel formula now at "D5"
OK C1 current_rows with old membership -> scope_changed, nothing published
OK C2 new current_rows proposal -> 20, 15, -20 ["=C2-B2","=C3-B3","=C4-B4"]
OK S1 candidate bytes changed after candidate_sha256 -> owner refuses, head unchanged "candidate bytes do not match candidate_sha256"
OK S2 resource outside profile -> unsupported_content/unknown_resource before engine; widened allow-list -> resource_lost after save()
OK S3 runtime refuses duplicate key, foreign base digest, other profile, unknown command, missing LF
OK S4 PHP and Node canonical bytes + SHA-256 equal on 407 vectors (RFC 8785 sample, Cyrillic, {} vs [], UTF-16 order, random doubles); lossy int/dup key/1e400 refused by both
OK S5 zero-based indices appear only in engine/univer.ts via engineIndex() [".getRange(engineIndex(coord.row)",".insertRowsBefore(engineIndex(row)"]
OK S6 examples/apply-*.json and scope-changed-*.json reproduced byte-for-byte by the runtime
PASS workbook-operation on node v24.12.0, 8.4.1
```
Базовые проверки после работы (файлы не менялись): `python3 plan/checks/verify-plan.py` → `OK: фаз 8, задач 22, критериев 37`; `python3 plan/checks/verify-fixtures.py` → SDK/live-write/workbook OK. JSON Schema: 4 примера валидны (`jsonschema` Draft202012).

## Не сделано: следующий шаг
**Выполнено в сессии 2 (2026-11-21 07:31), пункты 1–7 закрыты:**
- (1) создан `plan/spec/implementation/sheets-runtime-wire.md`: кадр и строгий разбор, правило целых токенов, теговое `CellContent`, таблица команд, `new_row_ids`/`identity_required`, ответы, таблица кодов → публичный код PHP (из `mapRuntimeError`), RFC 8785, невоспроизводимый digest (случайные ID стилей) → идемпотентность по отпечатку и квитанции, replay 6.1 без сравнения digest, политики `exact_base`/`revalidate_by_id`;
- (2) `sheets.md`: абзацы входа/примера/успеха/digest заменены ссылкой; пример исправлен на `"expected":{"number":5}` (b); шаг 3 «Атомарной записи» ссылается на политики (d); 5.1 — row ID выдаёт владелец (c); правило целых (a) живёт только в wire-spec. Абзац про CLI/stderr и абзац MVP-команд оставлены;
- (3) `workbook-flow.md`: ссылка на wire и абзац «Образец» с `submitWorkbookCommand`, `runOperation`, `WorkbookOwner::commit`/`verifyCandidate`;
- (4) `spreadsheets.md` «Прототип движка», п.5: `unknown_resource`/`resource_lost` → `unsupported_content` до записи, ссылка на run.sh;
- (5) строки «**Образец**» в 4.2 (`04-sheets.md`), 5.1 (`05-sheet-sdk.md`, вторая строка) и в строке 5.1 `ai.md`;
- (6) `verify-fixtures.py::verify_workbook_wire`: 4 примера по схеме, digest = JCS обёртки, 4 мутанта (голое expected, два тега, row 0, публичный код на приватном wire) отвергнуты;
- (7) S7 в `check.mjs`.

Фактический вывод:
```
$ python3 plan/checks/verify-fixtures.py
OK SDK: 34 exchanges, 68 messages, 17 rejected messages, 8 schema-valid proofs refused by the adapter
OK live-write: 10 ordered events, 11 fault variants
OK workbook: 35 → 41, row insertion, idempotent retry, conflict, cold cache 999 → 41
OK workbook wire: 4 examples schema-valid, digests are JCS of the wrapper, 4 mutants rejected
$ python3 plan/checks/verify-plan.py
OK: фаз 8, задач 22, критериев 37
$ bash plan/checks/workbook-operation/run.sh   (код 0)
…
OK S7 two imports of workbook.json: equal cells and style bodies, different style IDs and digests ["8f7d51d9","cf4fc279"]
PASS workbook-operation on node v24.12.0, 8.4.1
```

Исходный список (для истории):
1. Создать `plan/spec/implementation/sheets-runtime-wire.md`: поля, коды ошибок и их отображение у PHP (таблица из `Owner.php:mapRuntimeError`), RFC 8785, правило целых токенов, `CellContent`, `new_row_ids`, политики `exact_base`/`revalidate_by_id`, случайные ID стилей. Ссылки на схему и образец.
2. В `sheets.md` §«Внутренний обмен PHP и Node» абзацы 2–5 (вход, пример команд, успех/ошибки, digest) заменить ссылкой на wire-spec, абзац про CLI/stderr оставить. В шаге 3 «Атомарной записи» сослаться на политики.
3. `workbook-flow.md`: в шагах «Вычисление»/«Фиксация» сослаться на `vue-client.ts:submitWorkbookCommand` и `WorkbookOwner::commit` (locked re-check).
4. `spreadsheets.md` §«Прототип движка»: добавить строку про `unknown_resource`/`resource_lost` → `unsupported_content` до записи и ссылку на run.sh.
5. Фазы: в 04-sheets.md (4.2) и 05-sheet-sdk.md (5.1) добавить строку «**Образец** → … → `bash plan/checks/workbook-operation/run.sh` → `PASS`». В ai.md строку 5.1 дополнить ссылкой.
6. В `verify-fixtures.py` (раздел workbook) добавить проверку примеров по `runtime-v1.schema.json`: однострочник выше уже проходит.
7. В check.mjs добавить S7: два прогона seed дают разный sha при одинаковых ячейках (случайные ID стилей).

Сценарии `sheet_*` в office-sdk.json **не менялись**: противоречий с ними нет, все маркеры (11/12/13), формулы, значения и `scope_changed` воспроизведены.

## Автономный образец и будущая приёмка пакета
Образец доказывает алгоритм на закреплённых версиях: настоящий Univer в отдельных Node-процессах, PHP 8.4 владелец, барьеры через pipe, файловое хранилище с flock и атомарным rename. Пакетная приёмка 4.2/5.1 (`workbook-contract.sh`, `office-sheets.sh`) дополнительно требует:
- `AtomicDocumentMutation` на PostgreSQL вместо `FileDocumentState`, outbox и `v_sheets_commands`;
- Drive ACL и guard хоста (сейчас BOUNDARY);
- HTTP-транспорт Node `server.ts` с bearer, 413/503/504;
- Symfony Process вместо `proc_open`;
- настоящий Vue-компонент и браузер;
- SDK-адаптер AI с prepare/verify и digest предложения;
- restore и новое поколение (`generation_changed` есть, но не испытан).

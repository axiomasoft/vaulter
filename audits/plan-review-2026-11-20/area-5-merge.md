# Участок 5: слияние правок людей и агентов (сессия 2, 2026-11-21)

Выполнено без субагентов, последовательно. Окружение: PG 18.4, PHP 8.4.1, Node v24.12.0, Univer 1.5.2, yjs 13.6.31.

## (в) Протокол совместной книги — факт
- Совместное редактирование Univer (`@univerjs-pro/collaboration`, univer-server, преобразование конкурентных мутаций) входит в коммерческий Univer Pro. В Apache-2.0 ядре 1.5.2 есть только точки подключения: `ICommandService.onMutationExecutedForCollab` и флаги `fromCollab`/`onlyLocal` (`core/lib/types/services/command/command.service.d.ts:172-248`), а алгоритма преобразования нет.
- Перенесено: `sheets.md` 6.1 (развилка: Pro или собственный журнал команд Sheets с `rebase_cells`/`revalidate_by_id`), `spreadsheets.md`, шаг 1 карточки 6.1.

## (а) Книга: две правки разных ячеек больше не конфликтуют
Решение — политика `rebase_cells` для команд UI, которые меняют только содержимое ячеек:
- если голова сдвинулась после `If-Match`, те же команды выполняются на новой голове, а runtime заново сверяет каждое `expected`;
- если на том же листе между `If-Match` и головой была структурная команда (`structural_sheets` в квитанции) или публикация без квитанции, будет `version_conflict` с `detail.structural_receipts`.

Код: `Owner.php` (`commit`, `assertRebasable`, `structuralSheets`), `vue-client.ts:policyFor`, `http.php` (браузеру доступны только `exact_base`/`rebase_cells`), `owner-cli.php --mutant=no-structure-guard`.

Проверка `bash plan/checks/workbook-operation/run.sh` → PASS:
```
OK D1 edits of different cells: exact_base -> version_conflict, rebase_cells -> both kept [40,21,61]
OK D2 same cell: rebased command meets a changed expected value -> version_conflict, Bob keeps 6
OK D3 rows moved since If-Match: guard -> version_conflict; mutant without guard writes 7 into the plums row, pears keep 5 ["d-bob-plums"]
```
Перенесено: `sheets-runtime-wire.md` §5, `sheets.md` («Атомарная запись», шаг 2), `database/sheets.md` (состав fingerprint, `structural_sheets`), образец в 4.2.

## (б) Текст: CRDT сходится, смысл — нет
Факт: офлайн «15 → 20» и A42 «15 → 30» в Yjs дают «2030» или «3020». Порядок зависит от clientID: за три прогона встретились оба варианта. Решение — при commit записывать в evidence якоря Yjs `RelativePosition` вокруг заменённого участка, расширенного на один соседний символ с каждой стороны. `verify(current)` для journal сверяет это окно.

Код: `plan/examples/reference/live-write/effect-anchors.mjs`. Проверка `bash plan/checks/collaboration/live-write.sh` → exit 0 три раза:
```
OK offline_target_edit_arrives_later — merged text "Аванс в течение 3020 дней"; at_commit passed; current effect_anchor " 30 " → " 3020 " failed
OK sensitivity: anchors without neighbour characters — span-only window "30" passes although the text reads "Аванс в течение 3020 дней"
OK sensitivity: offline edit elsewhere in the block — "Предоплата в течение 30 дней": effect_anchor passed (window " 30 "), whole-block rule failed — false alarm the anchors remove
```
Перенесено: `agent-sdk.md` (`text_and_marks`, режим current для journal; форма wire не менялась), `collaboration.md` («Проверка результата»), 3.5-B (7/11).

## Осталось
- Отмена (Ctrl+Z) команды агента в живой комнате: не разобрано.
- Snapshot-текст: autosave человека после commit агента получает 409; слияние локального ввода в Vue не описано.
- `rebase_cells` для `set_values` поверх диапазона, который частично задела структурная команда другого листа, проверен только защитой «тот же лист». Межлистовые формулы пересчитываются runtime, но отдельного теста нет.
- Вариант (2) для 6.1 (собственный журнал) — пока только описание, без образца.

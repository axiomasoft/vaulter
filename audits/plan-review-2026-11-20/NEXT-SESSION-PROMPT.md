# Продолжение ревью plan/ (сессия 2)

Работаешь в корне Vaulter, без субагентов, последовательно. Это продолжение ревью по заданию `audits/opus-review-prompt.md`. Задание целиком не перечитывай: открывай только разделы, на которые ниже есть ссылки. Почти всё уже сделано и проверено. Осталось перенести найденное по участку 3 в спецификации, по возможности добавить проверки участка 2 и закрыть ревью итоговой таблицей.

## 0. Вход (5 минут)

1. Прочитай `audits/plan-review-2026-11-20/PROGRESS.md`: раздел «КАК ПРОДОЛЖИТЬ» и «Участок 1». Затем `area-3.md` целиком. Из `area-2.md` прочитай только «Следующий шаг».
2. Убедись, что исходная точка зелёная (одна команда):
   ```bash
   for c in "bash plan/checks/atomic-mutation/run.sh" "bash plan/checks/collaboration/live-write.sh" "bash plan/checks/workbook-operation/run.sh" "bash plan/checks/sdk/run.sh" "python3 plan/checks/verify-plan.py" "python3 plan/checks/verify-fixtures.py"; do $c >/tmp/o 2>&1; echo "$? | $c | $(tail -1 /tmp/o)"; done
   ```
   Ожидание — шесть строк с кодом 0. Если что-то красное, сначала почини.

## 1. Участок 3: перенести находки в план (главное)

Образец `plan/examples/reference/workbook-operation/` и проверка `plan/checks/workbook-operation/run.sh` готовы. Spec, phases и `office-sdk.json` пока не менялись. Выполни пункты 1–7 «Следующего шага» из `area-3.md`:

- Создать `plan/spec/implementation/sheets-runtime-wire.md` — приватный wire-контракт PHP ↔ Node. Норму брать из `runtime-v1.schema.json` и `src/wire.ts` / `php/Wire.php` образца, не придумывать заново. В контракте должны быть: теговое CellContent; правило целых вне ±(2^53−1) (принимаются, только если совпадают с ES-печатью double); `new_row_ids` / `identity_required`; политики `exact_base` и `revalidate_by_id`; коды ошибок; канонизация и digest. Добавить факт: `setNumberFormat` в Univer 1.5.2 создаёт стиль со случайным ID. Поэтому повторный расчёт не воспроизводит digest кандидата, и идемпотентность держится на отпечатке команды и квитанции. Это нужно для replay в 6.1.
- В `plan/spec/implementation/sheets.md` в разделах «Внутренний обмен PHP и Node» и 4.2 заменить дублирующую норму ссылкой на новый файл. Противоречия (a)–(d) из `area-3.md` исправить в самом документе-владельце. Дать ссылки из `workbook-flow.md` и `spreadsheets.md`.
- Добавить строку «**Образец** → целевой файл и символ → команда → ожидаемый результат» в карточки 4.2 (`plan/phases/04-sheets.md`) и 5.1 (`plan/phases/05-sheet-sdk.md`) и в строку 5.1 файла `plan/spec/implementation/ai.md`. Формат строк `### N.M`, `**После** — …` и `- **N.M-X** …` не менять: их парсит `verify-plan.py`.
- В `plan/checks/verify-fixtures.py` добавить проверку `examples/*.json` образца по `runtime-v1.schema.json`. Писать на stdlib, в стиле существующих функций.
- Добавить сценарий S7 (случайные ID стилей) в `plan/checks/workbook-operation/check.mjs`, если этого требует `area-3.md`.
- Проверить: `bash plan/checks/workbook-operation/run.sh`, `verify-plan.py`, `verify-fixtures.py`. В `area-3.md` отметить, что сделано, с фактическим выводом.

## 2. Участок 2: оставшиеся аварийные варианты (если хватит лимита)

Из 11 вариантов `plan/examples/live-write.json` пройдено 3. По `area-2.md` («Следующий шаг») добавь в `plan/checks/collaboration/live-write.test.mjs` по приоритету: `concurrent_duplicate` (два воркера, барьер `pg_blocking_pids`, без sleep), затем `generation_restored` / `late_delivery_after_restore`, затем остальные. Каждый вариант должен различать две реализации и сам проверять ожидаемый отказ. Если лимита мало, остановись после `concurrent_duplicate` и честно перечисли непройденные варианты в карточке 3.5-B (`plan/phases/03-text-sdk.md`) и в `area-2.md`. Проверка — `bash plan/checks/collaboration/live-write.sh`.

## 3. Финал

1. Прогони команду из шага 0.2 и `bash plan/checks/schema.sh` (если менялся SQL). Сохрани вывод в `audits/plan-review-2026-11-20/final.log`, укажи версии PG/PHP/Node.
2. Короткая сверка по зависимостям. Поищи по именам изменённых полей, что оба участника обмена согласованы. Это `writer` / `owner_key` (ai.md, database/ai.md), `documentId` / `connection()` (vaulter.md §3.3), `new_row_ids` и CellContent (sheets.md ↔ образец), `proposal_digest` (agent-sdk.md ↔ verify-fixtures.py). Устрани найденные расхождения.
3. Внеси в `PROGRESS.md` итог и отметь его как закрытый.
4. Ответ мне. Таблица из четырёх строк с колонками: участок | исправление или подтверждённое решение | файл для переноса | команда и результат | что осталось проверить при внедрении. Данные брать из `PROGRESS.md` и `area-2/3/4.md`. Отдельно — открытые вопросы с минимальным экспериментом и зависимой задачей:
   - строгий режим живой комнаты при нескольких Hub: штатный `@hocuspocus/extension-redis` обходит барьер, нужен собственный канал «принята позиция N»; задача 3.5;
   - дубли имён членов JSON на транспорте SDK (раздел «Транспорт» в `agent-sdk.md`);
   - Node 26 из engines Hub против фактических 24.12.

Правила: правки только в `plan/` и `audits/`; общие файлы правь точечно. Прохождение автономного образца и приёмку будущей пакетной интеграции фиксируй раздельно. После каждого завершённого пункта обновляй `PROGRESS.md`: лимит может кончиться.

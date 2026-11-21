# Участок 4 — digest предложения, адресная правка текста, verify

Статус: выполнено, проверки зелёные (2026-11-20).

## Прочитано
- `plan/spec/agent-sdk.md` «Команды содержимого»…«Ошибки»; `office-v1.schema.json`; `verify-fixtures.py` (`proposal_digest`, `check_verified`); `vaulter.md` §3.1; `ai.md` 3.3a/3.3d; `phases/03-text-sdk.md`, `05-sheet-sdk.md`.
- Действующий символ `packages/documents/src/Content/ContentHasher.php`.
- `mmccook/php-json-canonicalization-scheme` 1.0.0 (план: строка core в `ai.md`), commit `cd6d3e76`, тот же, что в lock AgenTools.
- Окружение: PHP 8.4.1 (mbstring), Node v24.12.0, npm 11.6.2, Python 3.12.3, jsonschema 4.26.0, composer 2.8.3. Корневой lock: prosemirror-model 1.25.12, prosemirror-transform 1.12.2, orderedmap 2.1.1.

## Подтверждённые факты
1. `ContentHasher::encode` не годится для digest: `\uXXXX` и `\/`, порядок байтов UTF-8 (U+1F600 после U+FB33), `{}`→`[]`, `{"0":"a","1":"b"}`→`["a","b"]`. Показано вызовом действующего класса в `digest_vectors.py`.
2. mmccook 1.0.0 расходится с JCS на 5 векторах: U+2028/2029 экранируются, `10.0`→`1`, `1.5e20`→`15`, `9007199254740993` печатается как есть, и один допустимый по схеме prepare с U+2028 в `replacement` даёт другой digest. Проверка: `compare.php`.
3. Прежний `verify-fixtures.py::proposal_digest` (json.dumps sort_keys) сортировал по code points. Заменён полным эталоном RFC 8785, все 7 digest фикстуры совпали.
4. `check_verified` требовал, чтобы `expected` совпадал с целым сегментом. Это уже правила спецификации; исправлено на `matched_segment` (перекрытие, один run).
5. В спецификации не были заданы: единица смещений, подсчёт перекрывающихся совпадений (PHP `substr_count` против JS `indexOf`), граница run для ненормализованного Tiptap JSON, понятие «контекста», коды отказов, представление `hardBreak`, семантика `text_exact` в current. В строке 3.3a неоднозначно звучало «неполный токен».

## Решения (внесены в spec)
- Цель v1: UTF-16, U+FFFC для inline-листа, run с учётом attrs, совпадения с перекрытием, ровно одно внутри одного run, контекст — весь блок. Коды: precondition_failed / invalid_input / unsupported_content.
- verify text_and_marks: `actual` = диапазон квитанции; text passed ⇔ текст блока равен зафиксированному; marks passed ⇔ marks с attrs.
- Профиль digest: RFC 8785, только безопасные целые по значению; PHP декодирует в `stdClass`; внутренние предусловия лежат в `v_ai_proposal_payloads` по `proposal_id`.

## Файлы
Созданы: `plan/examples/reference/proposal-digest/{README.md,vectors.json,build-vectors.py,php/ProposalDigest.php,node/proposal-digest.mjs,php-mmccook/{composer.json,composer.lock,.gitignore,compare.php}}`, `plan/examples/reference/text-replace/{README.md,package.json,package-lock.json,schema.mjs,replace-exact.mjs,replace_exact.php,vectors.json,exchange.json}`, `plan/checks/sdk/{run.sh,digest_vectors.py,text_exchange.py,text-replace.test.mjs}`.
Изменены точечно: `verify-fixtures.py` (jcs*, digest_profile, proposal_record, proposal_digest, matched_segment, check_verified, adapter_negatives, строка вывода), `agent-sdk.md` (Текст + правила, шаг 2 о digest, абзац outcome=passed), `ai.md` 3.3a/3.3d, `vaulter.md` §3.1 (абзац после тестового вектора), `phases/03` (Образец после 3.3-B), `phases/05` (Образец после 5.1-B), `office-v1.schema.json` (description у digest).

## Команды и вывод
- `bash plan/checks/sdk/run.sh` → `OK area 4`. Python: 16/16 JCS, 5/5 proposals, 13 полей digest; PHP и Node: все ok; mmccook: ровно 5 задокументированных расхождений; node --test 5/5 (`office.read response 387 B vs stored document 995 B`); exchange: verify = passed/failed/passed, 8 неверных ответов, допустимых по схеме, отвергнуты.
- Мутационный контроль: перевод PHP-подсчёта на неперекрывающийся валит тест «PHP mirror gives the same…».
- `python3 plan/checks/verify-plan.py` → `OK: фаз 8, задач 22, критериев 37`.
- `python3 plan/checks/verify-fixtures.py` → `OK SDK: 34 exchanges, 68 messages, 17 rejected messages, 8 schema-valid proofs refused by the adapter` (+ live, workbook OK).

## Осталось (вне участка)
- Строка core в `ai.md` (стр. 11) всё ещё называет mmccook для JCS. Нужно заменить на собственный канонизатор или оставить mmccook только для AgenTools (решает координатор).
- Дубли имён членов JSON на транспорте: stdlib-парсеры PHP/Node берут последнее значение. Решение `invalid_request` относится к разделу «Транспорт», не к этому участку.

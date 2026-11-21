# Ревью plan/ (2026-11-20): протокол прогресса

## КАК ПРОДОЛЖИТЬ (состояние на 2026-11-20 22:22, лимиты исчерпаны)

Контрольный перезапуск в 22:24: все шесть команд дали код 0 — atomic-mutation (`OK atomic-mutation…`), collaboration/live-write (`OK hocuspocus-paths…`), workbook-operation (`PASS`), sdk (`OK area 4`), verify-plan (`8/22/37`), verify-fixtures (`OK`). Субагенты в состоянии idle, их работа сохранена в файлах.

1. Участок 1 **готов**. Проверить, что ничего не сломалось: `bash plan/checks/atomic-mutation/run.sh` → код 0, 30 строк `OK`.
2. **Участок 2 готов** (22:22). Проверка `bash plan/checks/collaboration/live-write.sh` → exit 0, 4,5 с. Подробности в `area-2.md`. Главное: `beforeHandleMessage` не барьер, у каждого соединения своя очередь. Барьер строгой комнаты — `readOnly=true` у всех соединений, а запись идёт через envelope → sequencer → commit → применение сохранённых байтов с origin `OWN`. Обходят hook `DirectConnection.transact` (serverWrite) и `extension-redis`: пока они включены, live capability выключена. Открытый вопрос к продукту: нужен ли строгий профиль с несколькими Hub? Если да, нужен собственный Redis-канал «принята позиция N». Не пройдены 8 из 11 вариантов фикстуры (они относятся к будущей интеграции).
   **Участок 4 готов** (22:23). Проверка `bash plan/checks/sdk/run.sh` → `OK area 4`. Подробности в `area-4.md`. Главное: `verify-fixtures.py::proposal_digest` сортировал ключи по code points, а JCS требует UTF-16; заменён полным RFC 8785, все 7 digest фикстуры совпали. Правила цели v1 в agent-sdk.md: смещения UTF-16, U+FFFC вместо inline-листа, ровно одно совпадение (с перекрытием) внутри одного run, где marks сравниваются вместе с атрибутами. ContentHasher и mmccook 1.0.0 для digest непригодны; координатор исправил строку core в ai.md (стр. 11). Открытый вопрос: дубли имён членов JSON на транспорте (раздел «Транспорт» agent-sdk.md).
   **Участок 3: образец готов, перенос в spec не начат** (22:23). Проверка `bash plan/checks/workbook-operation/run.sh` → PASS (Univer 1.5.2, Node 24.12, PHP 8.4.1). Spec, phases и office-sdk.json не менялись, полуправок нет. Найденные противоречия пока закреплены только в образце: (a) целые вне 2^53−1 принимаются, если совпадают с ES-печатью double; (b) теговое CellContent вместо `"expected":5`; (c) row ID при вставке выдаёт владелец (`new_row_ids`, иначе `identity_required`); (d) политики `exact_base` и `revalidate_by_id`; (e) `setNumberFormat` в Univer создаёт стиль со случайным ID, поэтому повторный расчёт не воспроизводит digest, а идемпотентность держится на отпечатке и квитанции (важно для 6.1). **Следующий шаг** — пункты 1–7 в `area-3.md`: создать `plan/spec/implementation/sheets-runtime-wire.md`, заменить абзацы протокола в sheets.md ссылкой, дать ссылки в workbook-flow и spreadsheets, добавить строку «Образец» в 4.2/5.1 и ai.md 5.1, добавить в verify-fixtures проверку examples по схеме, добавить S7.
   Субагенты по указанию пользователя заново не запускаются.
3. Затем финал: `python3 plan/checks/verify-plan.py`, `python3 plan/checks/verify-fixtures.py`, `bash plan/checks/schema.sh` (если менялся SQL), прогоны образцов всех участков. Итоговая таблица из 4 строк: «участок / исправление / файл для переноса / команда и результат / что проверить при внедрении».
4. Исходное задание — `audits/opus-review-prompt.md`; если его там нет, первое сообщение в transcript беседы b44c28cb.
5. Git: `plan/` и `audits/` в репозитории не отслеживаются (были такими и до ревью). Коммит не делался. Каталог `plan/examples/reference/proposal-digest/php-mmccook/vendor/` — установленные Composer-зависимости участка 4, в коммит не включать.

Файл обновляется по ходу работы, чтобы ничего не потерялось при обрыве сессии. У каждого участка свой файл `area-N.md`, который ведёт его исполнитель.

## Исходная точка (измерено)

| Команда | Результат |
| --- | --- |
| `python3 plan/checks/verify-plan.py` | `OK: фаз 8, задач 22, критериев 37` |
| `python3 plan/checks/verify-fixtures.py` | `OK SDK: 34 exchanges, 68 messages, 17 rejected`; `OK live-write: 10 events, 11 faults`; `OK workbook: 35 → 41 ...` |
| `bash plan/checks/schema.sh` | PostgreSQL 18.4; office 31→63 tables; document race commit/rollback/switch OK; ~2.6 s |

Окружение: PostgreSQL 18.4 (/usr/lib/postgresql/18/bin), PHP 8.4.1 (pdo_pgsql, sodium), Node v24.12.0 (план упоминает Node 26), lock Hub: yjs 13.6.31, @hocuspocus/server 4.3.0.

## Исполнители

| Участок | Кто | Файл прогресса | Каталог образцов |
| --- | --- | --- | --- |
| 1. AtomicDocumentMutation, повтор после сбоя | координатор | этот файл, раздел «Участок 1» | `plan/examples/reference/atomic-mutation/`, `plan/checks/atomic-mutation/` |
| 2. Живая комната, A42 | субагент a50806ed | `area-2.md` | `plan/examples/reference/live-write/` |
| 3. Книга Vue ↔ Node ↔ PHP | субагент ea62f52b | `area-3.md` | `plan/examples/reference/workbook-operation/` |
| 4. Digest, ReplaceExact, verify | субагент 74693cfb | `area-4.md` | `plan/examples/reference/proposal-digest/`, `text-replace/` |

Новые субагенты не запускаются (указание пользователя от 22:07).

## Участок 1. Подтверждённые факты из исходников (ревизия b230f6e27)

1. `DocumentWriteLock::acquire` (packages/documents/src/Actions/Documents/DocumentWriteLock.php): T = `pg_advisory_xact_lock_shared(hashtext('vaulter:drives:'||driveId))`, далее `DriveLifecycle::assertWritable`, N = `TreeGuard::lock` → `lockForUpdate` строки v_nodes, D = `DocumentReadRepository::lockForNode` → `SELECT … FOR UPDATE` по `node_id`. Сам метод права не проверяет.
2. `DocumentOperations::writeContent` (стр. 241) вызывает `document(..., Ability::Edit)` → `OperationGuard::authorize` **до** транзакции. Затем `UpdateDocumentContentAction::execute` берёт блокировку. **Вывод:** на действующем пути отзыв права, зафиксированный между проверкой и блокировкой, не мешает записи. Для нового порта проверка Edit должна идти после N-блокировки. Это различающий тест для участка 1.
3. `RevokeNodeAccessAction` блокирует только строку своего узла (FOR UPDATE) и повышает `access_version`. Отзыв на предке не сериализуется с блокировкой документа. В READ COMMITTED проверка под блокировкой видит каждый отзыв, зафиксированный до неё. Отзыв, зафиксированный позже, линеаризуется после записи. Это допустимо, но так и нужно записать в спецификации.
4. `VaulterDatabase::transaction` (core, стр. 51) **повторяет callback до 3 раз** при 40001/40P01. Следствие для порта: callback `AtomicDocumentMutation::run` может выполниться повторно. Внутри него нельзя выполнять внешние эффекты, а receipt/outbox нужно вычислять заново из snapshot на каждой попытке. В спецификации этого нет.
5. `DocumentStateWriter::write` проверяет `expectedStateVersion`, `state_version += 1` на каждой эффективной записи, а `collab_epoch` повышается при Update с непустым `collab_protocol`. Это причина появления `write_mode`, она уже описана в DB0.
6. В `DocumentMutationState` по спецификации нет `documentId`. При этом `v_ai_text_commands.document_id` (FK) нужно заполнить в той же транзакции. **Решение:** добавить `documentId` в DTO, иначе одни исполнители перечитают его без блокировки, а другие запишут NULL.
7. В порту нет способа получить «то же физическое соединение». **Решение:** `LockedDocumentMutation::connection()` возвращает соединение текущей транзакции. Запись адаптером через другое соединение нарушает атомарность.
8. Фикстура `lost_response`: база 27 → человеческая правка p71 → 28 → commit → маркер квитанции 3/29. Значит, `source_generation/source_position` в v_ai_text_commands — это маркер **после** записи, а CAS в `replace` идёт по позиции, прочитанной под блокировкой, а не по base предложения.
9. На wire конфликт commit возвращается ошибкой `precondition_failed`, а status — `{state:"rejected", reason}`. Durable `rejected` хранится у владельца.
10. Роли Drive с правом edit: `writer`, `organizer`, `owner` (packages/drive/config/vaulter-drive.php).

## Участок 1. Выбранный порядок (реализуется в образце)

Владелец, одна транзакция OWNER: T → N → **проверка Edit под блокировкой** (при отказе `resource_unavailable`, ничего не пишется) → D → поиск исхода по ключу → сравнение fingerprint (`idempotency_conflict`) → при найденном исходе вернуть сохранённое без проверки режима/поколения → иначе сверить write_mode/generation/schema/цель → `replace` один раз → строка команды + evidence + outbox → COMMIT. Неправильная альтернатива для мутационного теста — «сначала режим, потом ключ»: после смены режима она теряет квитанцию.

Core, соединение CORE: route (ключ, proposal_id, fingerprint, owner_key) фиксируется **до** вызова владельца. После ответа владельца заполняется receipt_id, и только потом идёт ответ клиенту. При повторе owner_key берётся из route, а не из текущего режима.

## Статус

- [x] Базовые проверки
- [x] Чтение исходников участка 1
- [x] Образец PHP + PG (atomic-mutation): `bash plan/checks/atomic-mutation/run.sh` → exit 0, 30 строк OK, `OK atomic-mutation: all scenarios and mutants distinguished`, 2,3 с. Лог: `atomic-mutation.log`
  - A: kill после COMMIT владельца → маршрут CORE с receipt NULL → snapshot→journal (4/30) → повтор в новом процессе даёт тот же receipt и маркер 3/29; 1 строка команды, 1 событие команды, 2 события контента (человек + агент); чужой fingerprint → conflict (CORE и владелец); второй command_id на P → conflict; после отзыва commit/status → resource_unavailable без receipt. Мутант mode_first → generation_changed (квитанция потеряна).
  - B: отзыв держит N → писатель ждёт (pg_blocking_pids) → Edit запрещён, позиция 27. Мутант authorize_before_lock (порядок действующего writeContent) записывает после отзыва.
  - C: две параллельные попытки с одним ключом: commit → обе получают одну квитанцию; rollback X → эффект создаёт Y, эффект один.
  - D: 40001 на сервере внутри callback → повтор всего callback, одна строка, ответ = сохранённая квитанция.
  - E: человек изменил цель → durable rejected/precondition_failed, повтор возвращает то же.
  - F: смена режима до первого commit → generation_changed, одиночной замены нет.
- [x] Правки участка 1 (22:21):
  - `plan/spec/implementation/vaulter.md` §3.3: `connection()` в порту; `documentId` и `pending` в DTO; норма «callback выполняется один или несколько раз»; строка «Образец»; шаг 1 — порядок T → N → Edit → D и разбор отзыва на предке; шаг 2 — порядок «сначала исход по ключу, потом режим»; в тестах — перенос сценариев B, C, D.
  - `plan/spec/implementation/ai.md`: строки 3.3b–d (owner_key запечатывается при prepare, три commit, CAS lease, когда отказ не пишется); исправлен абзац после таблицы: писатель больше **не** выбирается по текущему режиму при commit, это противоречило database/ai.md (писатель закреплён в плане).
  - `plan/spec/database/ai.md` §3: три commit; source_generation/position = маркер после записи; абзац о приёмке ссылается на run.sh.
  - `plan/phases/03-text-sdk.md` 3.3 п.4 — строка «Образец»; `plan/phases/04-sheets.md` 4.2 п.1 — ссылка на порядок порта.
  - verify-plan OK (8/22/37), verify-fixtures OK (участок 4 добавил «8 schema-valid proofs refused»).
- [x] Сбор отчётов участков 2–4
- [x] Участок 3, перенос в spec (сессия 2, 2026-11-21 07:31): `sheets-runtime-wire.md` создан, sheets.md/workbook-flow.md/spreadsheets.md/04/05/ai.md обновлены, verify-fixtures проверяет wire-примеры, S7 добавлен. run.sh → PASS, verify-plan 8/22/37, verify-fixtures OK (+ `OK workbook wire: 4 examples …, 4 mutants rejected`). Подробности в `area-3.md`.
- [x] Участок 2, варианты (сессия 2, 07:35): `concurrent_duplicate`, `generation_restored`, `late_delivery_after_restore` с мутантами; live-write.sh → exit 0 ×3. Пройдено 6/11, остаток в карточке 3.5-B и `area-2.md`.
- [x] Финальный прогон (2026-11-21 07:36, `final.log`): PG 18.4, PHP 8.4.1, Node 24.12.0. Все 7 команд дали код 0: atomic-mutation, live-write, workbook-operation (PASS, включая S7), sdk (`OK area 4`), verify-plan 8/22/37, verify-fixtures (+ wire), schema.sh.
- [x] Сверка зависимостей: `owner_key` запечатывается при prepare (ai.md стр. 63/73 ↔ database/ai.md §3 ↔ ai-core.sql); `documentId` и `connection()` есть в vaulter.md §3.3; `new_row_ids` и CellContent совпадают в sheets.md, sheets-runtime-wire.md и образце, голого `"expected":5` больше нет нигде; digest предложения (agent-sdk.md п. 2: JCS, UTF-16) ↔ `verify-fixtures.py::proposal_digest`. Расхождений не найдено. В wire-spec добавлена оговорка: профиль чисел digest книги ≠ профиль digest предложения.

## ИТОГ — РЕВЬЮ ЗАКРЫТО (2026-11-21 07:37)

**Дополнение 07:55 — участок 5, слияние правок (`area-5-merge.md`):** `rebase_cells` для правок ячеек в книге (D1–D3, мутант без структурной защиты); якоря Yjs для `verify(current)` в живом тексте (offline overlap, два мутанта); факт про Univer Pro и развилка для 6.1. live-write проходит 7 из 11 вариантов.

Все четыре участка закрыты. Открытые вопросы: строгая комната при нескольких Hub (нужен свой Redis-канал «принята позиция N», задача 3.5); дубли имён членов JSON на транспорте SDK; Node 26 из engines Hub, а проверено только на 24.12. 5 из 11 вариантов live-write остаются приёмке пакета (карточка 3.5-B).

Файлы участка 1: `plan/examples/reference/atomic-mutation/` (autoload.php, src/…), `plan/checks/atomic-mutation/{run.sh,scenario.php,worker.php,mutants.php}`.

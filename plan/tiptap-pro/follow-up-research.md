# Дополнительное исследование готовности Vaulter

Прогоны на checkout `06576b393` от 2026-11-21. Работали с синтетическими данными, изолированными процессами и локальными зависимостями. Результаты ниже дополняют [проект backend](backend-design.md), [этапы](development-plan.md) и [матрицу](acceptance-matrix.md).

## 1. Гонка P/Q и срок действия решения

Две физические сессии PostgreSQL 18.4 работают с эталонной [схемой AI Core](../spec/database/sql/ai-core.sql). Первая держит lock, вторая реально блокируется, что подтверждает `pg_blocking_pids`. До commit другая сессия видит P pending без route и dispatch.

| Случай | Результат |
| --- | --- |
| Approve фиксируется первым | P approved, прежний второй запрос отклонения даёт ноль изменений. Один route и один dispatch. Q pending. |
| Reject фиксируется первым | P rejected без route/dispatch. Q pending. |
| Первый approve откатывается | Второй reviewer фиксирует reject. Строки route/dispatch первого запроса откатились. |
| Вход с другим digest | Q остаётся pending. |
| Цель pending, срок истекает в ожидании блокировки | У `UPDATE ... WHERE expires_at > clock_timestamp()` без предварительной блокировки получено **approved**, хотя срок прошёл до освобождения lock неизменившейся строки. При проверке после получения lock P остаётся pending без dispatch. |

Для production разделить захват строки и проверку срока на явные шаги одной транзакции. PostgreSQL гарантирует ожидание блокировки, а predicates, вычисленные до неё, могут устареть. Тот же порядок нужен для текущей политики и ACL. Для уже принятого решения порядок повтора другой: после текущей авторизации возвращается терминальное решение, срок проверяется при переходе из pending. Прототип проверяет host SQL и диспетчеризацию. Прогон с полным ACL, HTTP, owner commit и отзывом reviewer остаётся V1.3. [PostgreSQL row locks](https://www.postgresql.org/docs/18/explicit-locking.html#LOCKING-ROWS) · [Результат P/Q](results/vaulter-postgres-decisions-stream.json)

## 2. SSE, порядок видимости и повторы tool calls

Identity/sequence выделил 1 первой транзакции и 2 второй. Вторая зафиксировалась быстрее. Клиент, увидевший только 2 и переподключившийся с `Last-Event-ID: 2`, потерял 1 после её позднего commit. В том же прототипе транзакционное обновление `last_seq` на строке сессии упорядочило append. Конкурент по той же сессии ждал, конкурент по другой сессии записался, откат не оставил дырку. [Результат проверки SSE](results/vaulter-postgres-decisions-stream.json) · [Last-Event-ID](https://html.spec.whatwg.org/multipage/server-sent-events.html#the-last-event-id-header)

Решение — атомарно увеличивать счётчик сессии и добавлять событие в той же транзакции, что и переход ledger, с уникальностью `(session_id,seq)`. Для дельт группировать короткие чанки, чтобы не держать транзакцию через сетевой вызов. Outbox/notify только будит чтение базы. При reconnect читать по `seq > cursor ORDER BY seq`; при пропуске прав выдавать непрозрачный cursor без деталей. Два воркера по разным сессиям независимы.

Для V4 сохранять серверный `call_id` до постановки в очередь и сверять нормализованные аргументы по fingerprint. Повтор job читает этот ID из ledger, даже если модель способна выдать другое сетевое имя вызова. При перезапуске незавершённой генерации в модель передаются сохранённые завершённые tool calls и их результаты. Новая модельная просьба с теми же аргументами может быть новым намерением и проходит обычные предусловия/approval. Один digest аргументов не задаёт идентичность шага. Сквозной тест отдельно вбивает чужой digest в прежний call_id и две намеренно отдельные операции с равными аргументами. Минимальный SQL ledger смоделировал потерянный ответ после отдельного owner commit. Повтор по сохранённому command ID нашёл прежнюю квитанцию, эффект остался один. Другой digest не совпал со старым вызовом, а отдельный вызов с теми же аргументами получил свой ключ. V4 проверит эту последовательность с отдельными worker, Office SDK и потоком SSE.

## 3. Restore: тип субъекта, старые bytes и удаление якоря

Прикладной `404 node_not_found` локального checkout воспроизводился при аутентификации свежей комнаты. Два места fixture создают разные morph types. `E2eActingAs` создаёт `Vaulter\Testing\Fixtures\TestUser`. `FakeHostUserResolver` по умолчанию ждёт `Vaulter\Collaboration\Testing\Fixtures\CollaborationTestUser`. Прямой PHP-прогон подтвердил, что resolver возвращает null для настоящего субъекта HTTP-fixture. После передачи его morph type resolver восстанавливает тот же type+id. `CollaborationHub::actor` штатно скрывает отсутствующего субъекта через `node_not_found`. Сопоставление полного principal остаётся частью защищённого входа. Этого дефекта оказалось недостаточно для полного объяснения стенда. После выравнивания типа в копии provider и адаптации session/If-Match все три E2E по-прежнему остановились до sync на отказе Hub authenticate. Для следующего прогона отдельно зафиксировать фактически связанный resolver в Laravel, результат поиска principal и вердикт referenceContext по tenant/drive/node. [PHP-прогон](results/vaulter-restore-principal.json) · [HTTP-fixture](../../tests/Integration/E2E/E2eActingAs.php) · [Hub authority](../../packages/collaboration/src/CollaborationHub.php)

Обновление E2E-хелперов включает текущие поля публичной session (`write/read`, `generation`, `options.room`), обязательный If-Match для restore и единый тип субъекта. Перед приёмкой V2 надо прогнать заводской E2E и новый offline-case с настоящими браузерами и PostgreSQL.

Локальный Yjs 13.6.33 дал два результата:

- Вставка ровно у обоих краёв anchor сохранила выделение «15» при внутренней affinity. Частичное удаление сузило цитату до «5». Полное удаление вернуло две валидные позиции с **одинаковым** offset. При удалении всего блока обе позиции стали `null`. Resolver должен проверять непустоту диапазона и существование блока.
- На свежем Y.Doc с новыми CRDT IDs старый дифф замены ушёл в pending dependencies. Однако независимая старая вставка из ранее пустого именованного root успешно применилась на свежей основе при вызове `Y.applyUpdate` напрямую. Граница поколения находится в авторизации envelope до вызова Yjs. Новый seed сам по себе не отбрасывает все старые bytes. [Результат Yjs](results/vaulter-yjs-generation.json)

## 4. DOCX: сохранение в LibreOffice

Генератор создаёт собственный синтетический DOCX из XML без зависимости от Pro. Corpus включает 2×2 merge в сетке из трёх колонок, секции с explicit/inherited колонтитулами, resolved thread с reply, текстовые insert/delete, rPr change и экспериментальный custom XML part с группой replacement. Параграфные ID, цепочка `paraIdParent` и флаг `done` проверяются как смысловые связи. Прогон использовал временный профиль LibreOffice 24.2.7.2. [Формат комментариев](https://learn.microsoft.com/en-us/openspecs/office_standards/ms-docx/9660dacc-2ceb-4352-87d2-42ba1184f522) · [Параметры LibreOffice](https://help.libreoffice.org/latest/om/text/shared/guide/start_parameters.html?DbPAR=SHARED)

| Свойство | После сохранения | Следствие для приёмки |
| --- | --- | --- |
| Сетка/merges/ширины | Три исходные grid columns по 1800 dxa стали двумя 3600/1799, `gridSpan` исчез. Содержимое, bold, header row и вертикальное объединение сохранились. | Разделить геометрию видимых ячеек и точную адресную сетку. Для собственного точного профиля обе важны. Профиль внешнего редактора указывает, какие изменения сетки допустимы. |
| Секции | Наблюдаются прежние строки основных колонтитулов и явный page break. Ссылки inherited-колонтитулов материализованы, добавлены first/even footer variants. | Сверять эффективное отображение каждого варианта отдельно от схемы наследования. Строгая проверка требует сохранения обоих свойств. |
| Обсуждения | Root, range и resolve сохранились. Reply отсутствует в сохранённом файле. | Threaded-профиль нельзя принимать на этой версии LibreOffice. Если вход уже не содержит ответа, importer без исходника не может определить факт потери. |
| Revisions | `w:ins` 30, `w:del` 15, автор и `w:rPrChange` сохранились. Проверяемый custom XML part группы replacement отсутствует. | Фиксировать перенос insert/delete и обратимость группы отдельно. Чужой файл без подтверждённой группы можно представить как две операции, при сравнении с export manifest показать потерю группы. |

Это результат одного synthetic corpus и версии LibreOffice. Следующая квалификация — тот же набор в целевой версии Word и реальные синтетические файлы, созданные приложением-партнёром. Сохранение structural revisions, вложенных таблиц, notes/section breaks, разных шрифтов и ссылок проверяется отдельными образцами. [Полный semantic diff](results/vaulter-docx-roundtrip.json) · [Open XML revisions](https://learn.microsoft.com/en-us/office/open-xml/word/how-to-accept-all-revisions-in-a-word-processing-document)

## Воспроизведение

Команды из корня репозитория. PHP-прогон использует autoloader, собранный для текущего checkout. При отдельном потребителе передайте его путь первым аргументом скрипта. PostgreSQL поднимает собственный Unix socket без внешнего DSN. DOCX и LibreOffice-профиль создаются во временной папке. Для ручной проверки Word генератор с `--fixture <path>` сохраняет синтетический вход.

```bash
python3 plan/tiptap-pro/probes/postgres-probes.py --report plan/tiptap-pro/results/vaulter-postgres-decisions-stream.json
node plan/tiptap-pro/probes/yjs-generation.mjs --report plan/tiptap-pro/results/vaulter-yjs-generation.json
php plan/tiptap-pro/probes/restore-principal.php
python3 plan/tiptap-pro/probes/docx-roundtrip.py --report plan/tiptap-pro/results/vaulter-docx-roundtrip.json
```

## Что ещё исследовать по убыванию влияния на реализацию

| Риск и срок проверки | Эксперимент | Какое решение принимает результат |
| --- | --- | --- |
| V1. Отзыв права на предке и смена политики при решении | Блокировка N, параллельный revoke View/Edit/review на прямом узле и папке, отдельная БД хоста с задержкой доставки. Сравнить момент права в PHP с порядком commit и повтором status. | Где требуется общий fence, какие профили могут обещать строгий отзыв, что скрывается из истории. |
| V1. Долгое удержание статусов и удаление данных | Временные часы, после expiry удалить evidence и исходный payload, конкурирующий cleanup/outbox, повтор старого ключа. | Как удалять содержимое, сохраняя минимальный scoped tombstone и корректное `evidence_unavailable`. |
| V2. Реальные offline/reconnect и drift протокола | Два браузера, очередь в IndexedDB, crash после restore commit, повтор старых байтов до и после смены сессии. Параллельно измерить p95 ввода и catch-up от разных checkpoints. | Безопасный fallback черновика и порог включения strict комнаты. |
| V3. Пересекающиеся предложения, undo и IME | Малый корпус порядка accept/reject, split/join, вставки внутри чужого предложения, composition, прямой обход UI. Сравнить overlays и rich-text marks по повторным чтениям. | Какие структурные операции можно принимать автоматически и какие требуют пересогласования. |
| V4. Полноценный поток и tool ledger | Два subscribers, разрыв после delta и после owner commit, retry с другим provider ID, stale worker, отзыв View к части turn, отставание с retention reset. | Число durable событий, политика cursor и outbox, проверяемая семантика отмены и повторов. |
| V5. Межоперабельность DOCX | Прогнать один синтетический корпус и обратную сторону: сгенерировать файл в целевом Word/LibreOffice и импортировать. Сравнить semantic diff и preview потерь в браузере и Node. | Точные поддерживаемые профили D1–D4 и перечень оговорённых потерь. |
| После первых выпусков | Корпус вставки Word/Excel/Docs, разбиение страниц, сложные таблицы, slots/variables и их источники. Каждый эксперимент привязывать к отдельной пользовательской задаче. | Какие дополнительные editor capabilities требуются базовому профилю и какие остаются расширениями. [Каталог](module-catalog.md) |

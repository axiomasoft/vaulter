# Конкурентность

Vaulter защищает узел и содержимое документа **раздельными валидаторами**. Писатель читает
валидатор вместе с данными, возвращает его как ожидание и получает отказ, если между чтением и
записью что-то закоммитилось. Повторов за вас нет: отказ значит «перечитайте и решайте заново».

## Валидаторы

| Валидатор | Где | Растёт | Ожидание | Отказ |
|---|---|---|---|---|
| Sequence узла | `v_nodes.sequence` | при каждом изменении самого узла | `expectedSequence` | `412 node_sequence_mismatch` (`StaleNodeException`) |
| State version | `v_documents.state_version` | при каждой эффективной записи рабочего состояния документа | `expectedStateVersion` | `412 document_state_mismatch` (`StaleDocumentStateException`) |
| Epoch коллаборации | `v_documents.collab_epoch` | при записи, после которой открытые комнаты устарели | `expectedEpoch` (хаб, из своего токена) | `409 collaboration_epoch_mismatch` |

Правка узла и правка содержимого не сдвигают валидаторы друг друга: переименование документа не
ломает сохранение, а сохранение не ломает переименование.

## Матрица писателей

`Δ` — изменение от одного успешного вызова. «—» — вызов ничего не пишет.

| Операция | `sequence` | `state_version` | `collab_epoch` | Предусловие |
|---|---|---|---|---|
| rename, move, trash, restore из корзины, `setProperties` | +1 | — | — | `expectedSequence` |
| загрузка и восстановление ревизии файла | +1 | — | — | `expectedSequence` |
| удаление и закрепление ревизии файла | 0 | — | — | — (история, не голова) |
| star, unstar, `restrictInheritance` | 0 | 0 | 0 | — (персональное состояние или доступ, не узел) |
| создание документа | 0 | 1 | 0 | — |
| копия документа | источник 0; копия 0 | источник 0; копия 1 | источник 0; копия 0 | — |
| обновление содержимого (JSON) | 0 | +1 | +1, если у документа есть Yjs-состояние | `expectedStateVersion` |
| обновление с тем же содержимым | 0 | 0 | 0 | проверяется первым: устаревшее ожидание всё равно отклоняется |
| seed коллаборации (первая запись журнала) | 0 | 0 | 0 | у поколения нет ни записей, ни чекпоинта, а `state_version`, прочитанный хабом в `collaboration/hub/load`, всё ещё текущий (`409 collaboration_seed_conflict`) |
| append коллаборации | 0 | 0 | 0 | поколение текущее (`409 collaboration_epoch_mismatch`); ставит pending-маркер, версия состояния не двигается |
| чекпоинт коллаборации | 0 | +1, если состояние изменилось | 0 | поколение, `expected_checkpoint_version` (версия состояния) и покрываемый диапазон (`409 collaboration_checkpoint_conflict`) |
| восстановление ревизии документа | 0 | +1 | +1 | `expectedStateVersion` |
| snapshot (именованный чекпоинт) | 0 | 0 | 0 | — |
| закрепление ревизии документа, export | 0 | 0 | 0 | — |
| purge | узел и его агрегат исчезают | | | — |

События документа несут `stateVersion` и `epoch` после записи — из результата writer, поэтому
потребитель упорядочивает события содержимого, не перечитывая строку.

JSON-обновление документа **без** Yjs-состояния двигает `state_version`, но не epoch (устаревать
нечему — комнаты нет). Поэтому seed не может полагаться на один epoch: хаб читает JSON в
`collaboration/hub/load` и сидирует позже, и обновление в промежутке было бы затёрто seed, построенным из
старого текста. Seed (первая запись журнала) несёт загруженный `state_version` как `seed_state_version`, а запись сравнивает его под блокировкой
документа; сдвинувшаяся версия — `409 collaboration_seed_conflict`
(`context.expected_state_version`, `actual_state_version`), после чего хаб загружает документ
заново и сидирует из актуального JSON.

## Валидаторы представления (REST)

REST v1 отдаёт их как strong ETag (см. [справочник REST](/ru/reference/rest-api#валидаторы-и-предусловия)).

- Узел: `"{id}.{sequence}.{digest}"` — валидатор представления, которое получает **именно
  вызывающий**. Дайджест — первые 16 hex-символов SHA-256 от JSON узла этого вызывающего без
  `etag` (ключи отсортированы на всех уровнях), поэтому он меняется вместе со всем, что
  вызывающий видит изменившимся: способностями после гранта, `viewer.starred`, статусом скана
  головы, presentation, свойствами, головой содержимого, — а не только с `sequence`. Клиенты
  считают его непрозрачным.
- Содержимое документа: `"{documentId}.{epoch}.{stateVersion}"`; его тело не зависит от
  вызывающего, поэтому поколения достаточно. Расхождение — `412 document_state_mismatch`.
- У CRDT-комнаты нет `If-Match`: слияние параллельных правок — дело комнаты, хаб ограждён одним
  epoch, а append прошлого поколения получает `409`.

Условный маршрут выполняется в таком порядке: аутентификация → резолв drive/узла (с фильтром
арендатора) → поиск узла от имени вызывающего (нет `view` → `404`; нет способности для мутации → `403`) →
текущее представление вызывающего → сравнение `If-Match` → вызов операции с `sequence` этого представления как
ожиданием. Нет `If-Match` или `If-Match: *` — `428 precondition_required`; слабый или
устаревший тег — `412 node_sequence_mismatch` (`meta.node_id`, без текущего тега). Само
сравнение ещё не защищает от потерянного обновления: операция повторно проверяет ожидаемый
sequence на заблокированной строке внутри своей транзакции, поэтому запись, проскочившая между
проверкой и транзакцией, заканчивается тем же `412`. Окончательное удаление, восстановление и
восстановление ревизии принимают ожидание и через PHP API
(`TrashOperations::purge($node, $expectedSequence)`, `RestoreOptions::$expectedSequence`,
`FileOperations::restoreRevision($file, $revision, $expectedSequence)`) и проверяют его до
удаления строк или байтов, перемещения узла или резервирования квоты; в PHP параметр
по-прежнему необязателен.

## Проверки на заблокированной строке

Предусловия сравниваются со строкой, прочитанной **под блокировкой документа**, а не с моделью,
которую передал вызывающий. Так же проверяется «можно ли перезаписать это состояние»: запись без
ожидания всё равно отклоняется с `409 document_content_degraded`, если между предпроверкой вызывающего
и блокировкой закоммитилось восстановление ревизии более новой схемы или с деградацией. Отклонённая
запись не меняет содержимое, Yjs-состояние, версию схемы и чекпоинты.

## Версии агрегатов (реестр)

У каждого агрегата, который меняет операция `if_match`, своя колонка версии, поэтому compare-and-set никогда не
прячется за sequence чужой строки. Запись — `UPDATE … SET version = version + 1 WHERE id = ? AND version = ?`
на агрегате, заблокированном в транзакции операции; ноль затронутых строк — отказ. Политика каждой операции —
в [каталоге операций](/ru/reference/operations) ([ADR-0032](/adr/0032-operation-catalog-and-concurrency)).

| Агрегат | Версия | Тег представления (`etag`) | Отказ | Операции |
|---|---|---|---|---|
| `drive.node` | `v_nodes.sequence` | ETag узла (выше) | `412 node_sequence_mismatch` | переименование, перенос, корзина, восстановление, purge, свойства, ревизии |
| `drive.node_access` | `v_nodes.access_version` (гранты и наследование узла; `sequence` не меняется) | `GET nodes/{node}/grants`: id узла + `access_version` | `412 stale_write` | отзыв, ограничение и возврат наследования |
| `drive.share` | `v_node_shares.version` | id шара + `version` | `412 stale_write` | обновление, ротация, отзыв |
| `drive.drive` | `v_drives.version` | `GET drives/{drive}`: id + `version` | `412 stale_write` | архивирование, разархивирование, удаление |
| `documents.document` | `v_documents.state_version` | `{documentId}.{epoch}.{stateVersion}` | `412 document_state_mismatch` | обновление содержимого, восстановление |
| `documents.comment` | `v_node_comments.version` | id комментария + `version` | `412 stale_write` | resolve, удаление |
| `webhooks.endpoint` | `v_webhook_endpoints.version` | id endpoint'а + `version` | `412 stale_write` | обновление, деактивация, ротация секрета, удаление |

Тело ресурса несёт то же значение в `etag`, заголовок `ETag` его повторяет. Создание строки (грант, шар,
комментарий, endpoint, папка, загрузка) — `append`: `If-Match` не принимает. У операций `natural`,
`state_machine`, `generation` и `row_identity` `If-Match` тоже нет; их повтор безопасен по самому состоянию.

## Коды

| Ситуация | Ответ |
|---|---|
| `If-Match` отсутствует или `*` на маршруте `if_match` | `428 precondition_required` (для идемпотентности не сохраняется) |
| тег устарел или чужой | `412` — `node_sequence_mismatch` и `document_state_mismatch` сохраняют свои коды; остальные агрегаты отвечают `stale_write` (retryable `after_refetch`, receipt `cacheable`) |
| вызывающий не видит ресурс | `404` до чтения предусловия, поэтому тег не раскрывает скрытый ресурс |
| fence поколения (коллаборация) | `409 collaboration_*` |

`stale_write` называет в деталях `aggregate` и `id` ресурса и никогда не текущий тег; клиент перечитывает ресурс
и решает заново. Так как отказ `cacheable`, повтор под тем же `Idempotency-Key` повторяет его, а не гонится с
параллельным изменением. См. [коды ошибок](/ru/reference/errors).

## Частичные отказы

Каждая строка аудита §21.3 называет состояние после отказа, кто его восстанавливает и тест с инъекцией отказа. Строки `fast`
выполняются на sqlite в одном процессе и вызывают отказ один раз на одной стадии, так что следующая попытка действительно
выполняется. Однопроцессный fixture не доказывает поведение движка, kill процесса или гонку Redis: такие строки — `pending`
и относятся к runbook владельца (`serial` + `database` / `stand`).

| Сценарий | Состояние после отказа | Восстановление | Тест (`file::test`, строка) | Статус |
|---|---|---|---|---|
| Commit в БД прошёл, стадия доставки (consumer) упала | событие и строка доставки сохранены, `pending`, `attempts = 1`, задан `last_error` | outbox relay доставит на следующем проходе | `tests/Integration/Reliability/PartialFailureTest.php::keeps a committed event durable when its delivery stage throws once, and delivers it on the next pass` (51); `tests/Integration/Outbox/OutboxRelayTest.php::dead-letters a dead result at once and retries a consumer that throws` (180) | fast |
| Blob записан, транзакция метаданных упала | нет видимого узла и живого pin; staged-объект пригоден для повтора | reclaim осиротевших после grace | `packages/media/tests/Cas/BlobOrphanSweepTest.php::grace: тот же объект, состаренный за --orphan-grace, попадает в orphans` (169); `packages/media/tests/Cas/BlobCommitAtomicityTest.php::rollback не оставляет висящей строки blob` (29); `::commitStaged keeps the temp file reusable across an outer SQL rollback` (107); `packages/drive/tests/Concurrency/CompleteUploadRetryTest.php::SEC-R19-retry-exhausted maps the persistent failure to a retryable problem and keeps the staged bytes` (73) | fast |
| Метаданные есть, чтение blob упало | ошибка чтения ≠ «нет объекта»: метка и строка сохранены, живое не удаляется | scrub / heal перезаписывает объект при повторном приёме тех же байт | `packages/media/tests/Cas/BlobScrubTest.php::reports an object that cannot be read as unverified and does not mark it` (310); `packages/media/tests/Cas/BlobScrubHealTest.php::keeps the mark when the rewrite fails` (169); `::keeps the mark when the driver reports a failed write and the object is not there` (190) | fast |
| Дубль или переупорядочивание job | одно потребление на событие; один POST при пересекающихся job; завершённая доставка остаётся завершённой | receipt и состояние доставки делают повтор no-op | `tests/Integration/Reliability/PartialFailureTest.php::consumes an event once when the relay pass is repeated after the delivery completed` (72); `packages/webhooks/tests/Webhooks/DeliverWebhookTest.php::sends one POST when a duplicate job overlaps the HTTP attempt` (243) | fast |
| Webhook принят удалённой стороной, локальный таймаут | доставка остаётся transient с безопасной классификацией, повтор назначен | повтор использует ту же доставку, значит тот же `webhook-id` | `packages/webhooks/tests/Webhooks/DeliverWebhookTest.php::records a safe transport classification when the POST throws` (129); `::uses the configured retry schedule for next_retry_at` (158); `tests/Integration/Reliability/PartialFailureTest.php::retries an accepted-then-timed-out webhook delivery with the same webhook-id` (97) | fast |
| GC параллельно с новой ревизией | быстрого доказательства нет | блокировки на уровне движка | в этом checkpoint нет | pending: `serial` + `database` (pgsql), runbook владельца |

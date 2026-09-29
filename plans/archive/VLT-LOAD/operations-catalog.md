# VLT-LOAD — Каталог операций и размерных профилей (P1.2)

Единый список наблюдаемых операций vaulter, попадающих под нагрузку, с ID `OP-nn`, якорем
`file:line`, классом и структурным параметром стоимости. Вход для SLO-таблицы (P1.3), k6-сценариев
(P2) и EXPLAIN-целей (P3) — три артефакта ссылаются на эти же ID, покрытие проверяется машинно
(см. `phases/P1.md` P1.3/P1.4 Validation).

Обход выполнен по `Route::` во всех трёх пакетах с публичными маршрутами (`packages/core/routes/`,
`packages/drive/routes/`, `packages/documents/routes/` — `packages/media` и `packages/filament`
своих `routes/` не несут) плюс `Console/`, `Webhooks/`, `Cas/BlobGarbageCollector.php`,
`Collaboration/`. Итог: **56** маршрутов `Route::`, из них **38** в каталоге, **18** — в разделе
«Исключённые входы» (каждый с причиной); плюс **9** фоновых/очередных операций без HTTP-входа.
**47** строк `OP-nn` итого.

Класс: `интерактивная` (пользователь ждёт ответа) · `стриминговая` (тело течёт, важны throughput/RSS,
не p95-длительность) · `фоновая` (артизан-команда/воркер, важна пропускная способность и отсутствие
unbounded-scan).

---

## 1. Upload — tus (resumable, chunk-based)

| OP-nn | Операция | Вход (роут/команда) | Якорь file:line | Класс | Размерный/структурный параметр | Доля в миксе [гипотеза] | Связанные L-долги |
|:--|:--|:--|:--|:--|:--|:--|:--|
| OP-01 | tus: создание resumable-сессии | `POST storages/{storage}/resumable-uploads` | `packages/drive/src/Http/Controllers/Uploads/TusUploadController.php:67` | интерактивная | целевой размер файла (`Upload-Length`) — определяет число последующих PATCH | Низкая, 1 на файл — [гипотеза: 1 create на N чанков, N зависит от chunk_size] | — |
| OP-02 | tus: PATCH-чанк (запись части тела, incl. финальный чанк = скрытое завершение) | `PATCH resumable-uploads/{upload_id}` | `packages/drive/src/Http/Controllers/Uploads/TusUploadController.php:134` | стриминговая | размер чанка (`chunk_size`), суммарно — размер файла; spill-to-disk на 2 MiB (`StagedUpload::append`) | Высокая — доминирующая операция upload-миска, повторяется N раз на файл | — |
| OP-03 | tus: HEAD Upload-Offset (resume) | `HEAD resumable-uploads/{upload_id}` | `packages/drive/src/Http/Controllers/Uploads/TusUploadController.php:106` | интерактивная | не зависит от размера файла — read сессии | Низкая, только при разрыве соединения — [гипотеза: ~5–10 % сессий резюмируются] | — |
| OP-04 | tus: GET статус сессии | `GET resumable-uploads/{upload_id}` | `packages/drive/src/Http/Controllers/Uploads/TusUploadController.php:223` | интерактивная | не зависит от размера файла | Низкая — клиентский polling | — |
| OP-05 | tus: abort (DELETE, разделяет физический роут с S3-abort) | `DELETE resumable-uploads/{upload_id}` | `packages/drive/src/Http/Controllers/Uploads/TusUploadController.php:180` | интерактивная | объём уже записанных частей (temp-очистка) | Низкая — [гипотеза: ~2–5 % сессий обрываются] | — |

## 2. Upload — S3-multipart

Инициализация S3-multipart сессии физически проходит через тот же роут `POST
.../resumable-uploads` (OP-01) — `TusUploadController::create()` жёстко задаёт
`protocol: UploadProtocol::Tus` (:80), отдельного S3-initiate-роута в коде нет; abort также разделяет
DELETE-роут (OP-05). Ниже — операции, уникальные для S3-протокола.

| OP-nn | Операция | Вход (роут/команда) | Якорь file:line | Класс | Размерный/структурный параметр | Доля в миксе [гипотеза] | Связанные L-долги |
|:--|:--|:--|:--|:--|:--|:--|:--|
| OP-06 | S3-multipart: putPart (запись/замена одной части) | `POST resumable-uploads/{upload_id}/parts` | `packages/drive/src/Http/Controllers/Uploads/S3MultipartController.php:56` | стриминговая | размер части (ограничен `max_chunk_bytes`=64 MiB — `readPartWithinLimit()`), число частей на файл | Средняя — [гипотеза: S3-протокол выбирают клиенты, знающие о parallel-part-upload] | — |
| OP-07 | S3-multipart: complete (сборка всех частей → commit) | `POST resumable-uploads/{upload_id}/complete` | `packages/drive/src/Http/Controllers/Uploads/S3MultipartController.php:85` (сборка — `S3MultipartHandler.php:152-188`) | стриминговая (**сегодня — НЕ стриминговая по факту**, вся сумма частей в RAM) | суммарный размер файла (до 5 ГиБ) — `max_chunk_bytes` ограничивает ОДНУ часть, не сумму | Низкая по частоте (1 на файл), но пиковая по памяти | **L-01** |

## 3. Download

| OP-nn | Операция | Вход (роут/команда) | Якорь file:line | Класс | Размерный/структурный параметр | Доля в миксе [гипотеза] | Связанные L-долги |
|:--|:--|:--|:--|:--|:--|:--|:--|
| OP-08 | Скачивание текущей версии файла | `GET nodes/{node}/download` | `packages/drive/src/Http/Controllers/DownloadController.php:47` | стриминговая | размер файла; 1 PHP-воркер занят на весь стрим (AUDIT-VLT D15, нет presigned-redirect, :26-31) | Высокая — самая частая read-операция на файлы | — |
| OP-09 | Скачивание конкретной версии | `GET nodes/{node}/versions/{version}/download` | `packages/drive/src/Http/Controllers/DownloadController.php:59` | стриминговая | то же + число версий файла (для резолва) | Низкая — история версий смотрят реже текущей | — |
| OP-10 | Скачивание по публичной share-ссылке | `GET share/{token}/download` | `packages/core/src/Http/Controllers/Sharing/PublicShareController.php:102` | стриминговая | размер файла; неаутентифицированный трафик, `throttle:vaulter-public` по IP | Средняя — внешние получатели share-ссылок | — |

## 4. Дерево (storages/nodes)

| OP-nn | Операция | Вход (роут/команда) | Якорь file:line | Класс | Размерный/структурный параметр | Доля в миксе [гипотеза] | Связанные L-долги |
|:--|:--|:--|:--|:--|:--|:--|:--|
| OP-11 | Просмотр карточки хранилища | `GET storages/{storage}` | `packages/drive/src/Http/Controllers/StorageController.php:55` | интерактивная | O(1) | Низкая — читается редко (навигация уже внутри storage) | — |
| OP-12 | Листинг узлов хранилища (children) | `GET storages/{storage}/nodes` | `packages/drive/src/Http/Controllers/NodeController.php:81` | интерактивная | ширина узла (число детей); при grants — `constrainToAccessible()` → `candidateNodeIds()` (N-CTE per grant-anchor) | Высокая — базовая навигационная операция | **L-06** |
| OP-13 | "Shared with me" (кросс-storage листинг) | `GET shared` | `packages/drive/src/Http/Controllers/NodeController.php:109` | интерактивная | число grant-anchor'ов у актора — прямой вход в N-CTE | Средняя — только при включённом фиче-флаге `grants` | **L-06** |
| OP-14 | Поиск/фильтр по узлам | `GET storages/{storage}/search` | `packages/drive/src/Http/Controllers/NodeController.php:307` | интерактивная | глубина/ширина дерева-скоупа поиска + grants-скоуп | Средняя | **L-06** |
| OP-15 | Листинг корзины (trash) | `GET storages/{storage}/trash` | `packages/drive/src/Http/Controllers/NodeController.php:325` | интерактивная | число trashed-узлов (частичный индекс `trashed_at`) | Низкая | — |
| OP-16 | Создание папки | `POST storages/{storage}/folders` | `packages/drive/src/Http/Controllers/NodeController.php:158` | интерактивная | глубина родителя (≤ `DEFAULT_MAX_DEPTH`=64) | Средняя | — |
| OP-17 | Загрузка малого файла (не-resumable, single-shot) | `POST storages/{storage}/uploads` | `packages/drive/src/Http/Controllers/NodeController.php:178` | стриминговая (тело целиком, но малый файл — не multi-GB путь) | размер файла (для этого пути ожидаемо малый — крупные идут через tus/S3) | Средняя | — |
| OP-18 | Просмотр карточки узла | `GET nodes/{node}` | `packages/drive/src/Http/Controllers/NodeController.php:142` | интерактивная | O(1) | Высокая | — |
| OP-19 | Переименование узла | `PATCH nodes/{node}` | `packages/drive/src/Http/Controllers/NodeController.php:214` | интерактивная | уникальность имени в родителе (partial-UNIQUE индекс) | Низкая | — |
| OP-20 | Перемещение/reparent узла | `POST nodes/{node}/move` | `packages/drive/src/Http/Controllers/NodeController.php:227` | интерактивная | размер поддерева — `MoveNodeAction::assertNoCycle()` грузит ПОЛНОЕ поддерево в PHP, O(subtree) | Низкая по частоте, но дорогая на широких деревьях | **L-06** |
| OP-21 | Копирование узла | `POST nodes/{node}/copy` | `packages/drive/src/Http/Controllers/NodeController.php:246` | интерактивная | размер поддерева (если копия рекурсивна для папки) | Низкая | — |
| OP-22 | Восстановление из корзины | `POST nodes/{node}/restore` | `packages/drive/src/Http/Controllers/NodeController.php:277` | интерактивная | глубина восстанавливаемого поддерева | Низкая | — |
| OP-23 | Безвозвратное удаление | `DELETE nodes/{node}/force` | `packages/drive/src/Http/Controllers/NodeController.php:289` | интерактивная | размер поддерева + связанные blob-ссылки (ref_count) | Низкая | — |
| OP-24 | Перемещение в корзину (soft-delete) | `DELETE nodes/{node}` | `packages/drive/src/Http/Controllers/NodeController.php:265` | интерактивная | размер поддерева (каскад trashed_at) | Средняя | — |

## 5. Активность (v_activity, write-hottest)

| OP-nn | Операция | Вход (роут/команда) | Якорь file:line | Класс | Размерный/структурный параметр | Доля в миксе [гипотеза] | Связанные L-долги |
|:--|:--|:--|:--|:--|:--|:--|:--|
| OP-25 | Чтение фида активности хранилища | `GET storages/{storage}/activity` | `packages/core/src/Http/Controllers/Activity/ActivityController.php:44` | интерактивная | глубина пагинации по `v_activity` (500 млн строк на тире L — `reference-env.md`); GIN `context jsonb_path_ops` при фильтрах | Средняя | — |
| OP-26 | Чтение фида активности узла | `GET nodes/{node}/activity` | `packages/core/src/Http/Controllers/Activity/ActivityController.php:62` | интерактивная | то же, скоуп по node | Средняя | — |

Запись в `v_activity` — не отдельный HTTP-вход: она встроена (`ActivityRecorder`, one row/event) в
каждую мутирующую операцию каталога (OP-16, OP-19-OP-24, OP-27-OP-32, OP-34-OP-38 и т.д.) — это
write-нагрузка, приходящая пропорционально ИМ, а не собственная операция.

## 6. Документы (document-версионирование)

| OP-nn | Операция | Вход (роут/команда) | Якорь file:line | Класс | Размерный/структурный параметр | Доля в миксе [гипотеза] | Связанные L-долги |
|:--|:--|:--|:--|:--|:--|:--|:--|
| OP-27 | Создание document-узла | `POST storages/{storage}/documents` | `packages/documents/src/Http/Controllers/DocumentNodeController.php:68` | интерактивная | O(1) на создание (контент пуст/шаблон) | Низкая | — |
| OP-28 | Чтение текущего контента документа | `GET nodes/{node}/content` | `packages/documents/src/Http/Controllers/DocumentNodeController.php:90` | интерактивная (при большом снапшоте — ближе к стриминговой) | размер снапшота контента | Высокая — открытие документа | — |
| OP-29 | Автосейв/обновление контента | `PATCH nodes/{node}/content` | `packages/documents/src/Http/Controllers/DocumentNodeController.php:121` | интерактивная | размер payload + частота автосейва клиента | Высокая — периодический автосейв активных редакторов | — |
| OP-30 | Листинг именованных версий | `GET nodes/{node}/document-versions` | `packages/documents/src/Http/Controllers/DocumentNodeController.php:106` | интерактивная | число версий узла | Низкая | — |
| OP-31 | Снапшот именованной версии | `POST nodes/{node}/document-versions` | `packages/documents/src/Http/Controllers/DocumentNodeController.php:140` | интерактивная | размер контента на момент снапшота | Низкая | — |
| OP-32 | Восстановление версии | `POST nodes/{node}/document-versions/{version}/restore` | `packages/documents/src/Http/Controllers/DocumentNodeController.php:157` | интерактивная | размер восстанавливаемого снапшота | Низкая | — |
| OP-33 | Экспорт документа | `GET nodes/{node}/export` | `packages/documents/src/Http/Controllers/DocumentNodeController.php:199` | стриминговая | размер экспортируемого документа (конвертация формата) | Низкая | — |

## 7. Коллаборация и комментарии

| OP-nn | Операция | Вход (роут/команда) | Якорь file:line | Класс | Размерный/структурный параметр | Доля в миксе [гипотеза] | Связанные L-долги |
|:--|:--|:--|:--|:--|:--|:--|:--|
| OP-34 | Выдача collab-сессии (issuance) | `POST nodes/{node}/collab/token` | `packages/documents/src/Http/Controllers/NodeCollaborationController.php:60` (эвалюатор — `CollaborationSessionService::issue()`, `packages/documents/src/Collaboration/CollaborationSessionService.php:40`) | интерактивная | нет cap/rate-limit на issuance (в отличие от upload-сессий, §1.2 плана) | Средняя — раз на открытие совместного редактирования | — |
| OP-35 | Server-to-server: аутентификация collab-хаба | `POST collab/authenticate` | `packages/documents/src/Http/Controllers/NodeCollaborationController.php:79` | фоновая (S2S callback, без пользовательского ожидания) | вызывается хабом (Reverb/Hocuspocus) на каждое подключение участника к комнате | Средняя — пропорциональна числу участников сессии | — |
| OP-36 | Server-to-server: персист снапшота документа | `POST collab/store` | `packages/documents/src/Http/Controllers/NodeCollaborationController.php:110` | фоновая (S2S callback) | размер Yjs-снапшота; частота = периодичность автосейва хаба | Высокая — при активной совместной сессии тикает часто | — |
| OP-37 | Чтение фида комментариев узла | `GET nodes/{node}/comments` | `packages/documents/src/Http/Controllers/NodeCommentController.php:50` | интерактивная | число комментариев узла | Низкая | — |
| OP-38 | Добавление комментария (broadcast) | `POST nodes/{node}/comments` | `packages/documents/src/Http/Controllers/NodeCommentController.php:57` | интерактивная + фоновый fan-out (`ShouldBroadcast` → broadcast-queue → Reverb) | O(1) запись + broadcast-queue job на каждый коммент (§1.2 плана) | Низкая-средняя | — |

## 8. Очередь / outbox / webhook

| OP-nn | Операция | Вход (роут/команда) | Якорь file:line | Класс | Размерный/структурный параметр | Доля в миксе [гипотеза] | Связанные L-долги |
|:--|:--|:--|:--|:--|:--|:--|:--|
| OP-39 | Запись в outbox (встроена в каждую событие-порождающую мутацию, НЕ отдельный HTTP-вход) | вызывается изнутри Actions (напр. webhook-endpoint-триггерящие мутации) | `packages/core/src/Webhooks/OutboxRecorder.php:131` (`stage()`) | фоновая (embedded write) | идемпотентен по `event_id`; частота = частота мутаций, подписанных хотя бы одним endpoint'ом | Пропорциональна доле мутирующих OP выше — не собственный трафик | — |
| OP-40 | Relay-тик: claim + рассылка outbox-строк | `php artisan vaulter:relay-outbox` (сочетает `OutboxRelay::process()` + `WebhookRetryDispatcher`-довызов) | `packages/core/src/Console/RelayOutboxCommand.php:29` (→ `OutboxRelay::process(limit=100)`, `packages/core/src/Webhooks/OutboxRelay.php:38`; `subscribedEndpoints()` — O(endpoints) в PHP, `:100`) | фоновая | backlog `v_outbox` (2 млн на тире L, `reference-env.md`); число активных endpoint'ов (O(endpoints) на каждую строку) | Высокая частота тика (cron/scheduler), объём варьирует backlog'ом | **L-05** |
| OP-41 | Доставка одного webhook (queued job) | Laravel Queue (`ShouldQueue`) | `packages/core/src/Jobs/Webhooks/DeliverWebhook.php:55` | фоновая | без `$tries`/`$timeout`/`$backoff` (§1.2 плана); идемпотентен (no-op на terminal); 1 job на попытку доставки | Высокая — пропорциональна `v_webhook_deliveries` | — |
| OP-42 | Ретрай-свип: claim due-доставок | `php artisan vaulter:retry-webhooks` | `packages/core/src/Console/RetryWebhooksCommand.php:29` (→ `WebhookRetryDispatcher::claimDue()`, `packages/core/src/Webhooks/WebhookRetryDispatcher.php:54`) | фоновая | **без limit** — лочит и пере-армит ВСЕ due-строки за один тик (§1.2 плана) | Высокая частота тика | **L-04** |
| OP-43 | Прюн старых outbox/webhook-строк | `php artisan vaulter:prune-webhooks` | `packages/core/src/Console/PruneWebhooksCommand.php:40` | фоновая | объём истёкших/доставленных строк, подлежащих удалению | Низкая частота (обычно daily) | — |

## 9. Фоновые (sweeper / GC / reconcile)

| OP-nn | Операция | Вход (роут/команда) | Якорь file:line | Класс | Размерный/структурный параметр | Доля в миксе [гипотеза] | Связанные L-долги |
|:--|:--|:--|:--|:--|:--|:--|:--|
| OP-44 | Свип просроченных upload-сессий | `php artisan vaulter:sweep-uploads` (**не зарегистрирован в расписании пакетом** — §1.2 плана, L-08) | `packages/drive/src/Console/SweepUploadsCommand.php:32` | фоновая | `->get()` **без limit/chunk** — материализует ВСЕ кандидаты; объём — 1 млн просроченных сессий на тире L | Частота = частота cron-запуска хостом | **L-02** |
| OP-45 | Окончательная очистка корзины (истёкший `trashed_at`) | `php artisan vaulter:purge-trash` | `packages/drive/src/Console/PurgeTrashCommand.php:46` | фоновая | объём trashed-узлов старше retention-окна | Частота = daily/cron | — |
| OP-46 | GC блобов (two-phase mark+sweep) | `php artisan vaulter:gc-blobs` | `packages/media/src/Console/GcBlobsCommand.php:26` (→ `BlobGarbageCollector::collect()`, `packages/media/src/Cas/BlobGarbageCollector.php:57`; `candidates()` — `->cursor()` без batch-cap, `:293`) | фоновая | 1,5 млн orphan-блобов на тире L (объём строк) vs горячий набор ≈300 тыс./≈250 ГиБ физических байт (§2.2 `reference-env.md` — два разных числа) | Частота = cron; длительность растёт с объёмом orphan'ов | **L-03** |
| OP-47 | Реконсиляция блобов (storage↔БД сверка) | `php artisan vaulter:reconcile-blobs` | `packages/media/src/Console/ReconcileBlobsCommand.php:41` | фоновая | общий объём `v_blobs` × листинг физического storage | Низкая частота (maintenance-режим) | — |

---

## Исключённые входы

Каждый `Route::`, не попавший в каталог выше, с причиной.

| Роут | Контроллер::метод | Файл | Причина исключения |
|:--|:--|:--|:--|
| `GET nodes/{node}/grants` | `GrantController::index` | `packages/core/src/Http/Controllers/Access/GrantController.php:41` | Конфигурация ACL, низкая частота — не представительна для нагрузочного микса |
| `POST nodes/{node}/grants` | `GrantController::store` | `packages/core/src/Http/Controllers/Access/GrantController.php:53` | То же (admin/config) |
| `DELETE nodes/{node}/grants` | `GrantController::destroy` | `packages/core/src/Http/Controllers/Access/GrantController.php:65` | То же (admin/config) |
| `GET nodes/{node}/shares` | `ShareController::index` (импортирован как `NodeShareController`) | `packages/core/src/Http/Controllers/Sharing/ShareController.php:46` | Конфигурация share-ссылок, низкая частота |
| `POST nodes/{node}/shares` | `ShareController::store` | `packages/core/src/Http/Controllers/Sharing/ShareController.php:58` | То же (admin/config) |
| `DELETE nodes/{node}/shares/{share}` | `ShareController::destroy` | `packages/core/src/Http/Controllers/Sharing/ShareController.php:74` | То же (admin/config) |
| `GET webhook-endpoints` | `WebhookEndpointsController::index` | `packages/core/src/Http/Controllers/Webhooks/WebhookEndpointsController.php:43` | Глобальная admin-конфигурация подписчиков, не пользовательский трафик |
| `POST webhook-endpoints` | `WebhookEndpointsController::store` | `packages/core/src/Http/Controllers/Webhooks/WebhookEndpointsController.php:55` | То же (admin/config) |
| `PATCH webhook-endpoints/{endpoint}` | `WebhookEndpointsController::update` | `packages/core/src/Http/Controllers/Webhooks/WebhookEndpointsController.php:68` | То же (admin/config) |
| `POST webhook-endpoints/{endpoint}/deactivate` | `WebhookEndpointsController::deactivate` | `packages/core/src/Http/Controllers/Webhooks/WebhookEndpointsController.php:79` | То же (admin/config) |
| `DELETE webhook-endpoints/{endpoint}` | `WebhookEndpointsController::destroy` | `packages/core/src/Http/Controllers/Webhooks/WebhookEndpointsController.php:90` | То же (admin/config) |
| `GET share/{token}` | `PublicShareController::show` | `packages/core/src/Http/Controllers/Sharing/PublicShareController.php:63` | Дешёвое чтение метаданных share; доминирующая стоимость канала уже покрыта OP-10 (download) |
| `GET share/{token}/nodes` | `PublicShareController::nodes` | `packages/core/src/Http/Controllers/Sharing/PublicShareController.php:82` | То же — дешёвый листинг метаданных, не отдельная нагрузочная цель |
| `OPTIONS resumable-uploads/{upload_id}` | `TusUploadController::options` | `packages/drive/src/Http/Controllers/Uploads/TusUploadController.php:201` | Protocol-discovery без резолва сессии (tus CORE), стоимость пренебрежимо мала, не моделируется в миксе |
| `PATCH nodes/{node}/document-versions/{version}/keep-forever` | `DocumentNodeController::releaseVersion` | `packages/documents/src/Http/Controllers/DocumentNodeController.php:175` | Низкочастотный admin-переключатель пина (снятие `keep_forever`) |
| `GET nodes/{node}/handler` | `DocumentNodeController::handler` | `packages/documents/src/Http/Controllers/DocumentNodeController.php:188` | Дешёвое чтение дескриптора обработчика, O(1), не представительно |
| `POST nodes/{node}/comments/{comment}/resolve` | `NodeCommentController::resolve` | `packages/documents/src/Http/Controllers/NodeCommentController.php:68` | Низкочастотный toggle-статус, не отдельная нагрузочная цель |
| `DELETE nodes/{node}/comments/{comment}` | `NodeCommentController::destroy` | `packages/documents/src/Http/Controllers/NodeCommentController.php:81` | Низкочастотное удаление, не представительно |

Итого: 56 маршрутов `Route::` найдено обходом (`grep -rn "Route::" packages/*/routes/`) — 38 в
каталоге (§1–§7), 18 здесь. `packages/media` и `packages/filament` не регистрируют собственных
`routes/*.php` (Filament UI ходит через тот же API-контракт core/drive/documents — обходится этими
же маршрутами, отдельного входа не создаёт).

**Dev-tooling консольные команды, исключённые из каталога (не runtime-нагрузка хоста):**
`packages/core/src/Console/MakePanelCommand.php`, `packages/core/src/Console/PanelMigrationsCommand.php`
— генераторы кода/схемы, выполняются разработчиком при setup, не под пользовательской нагрузкой.

---

## Связь с Findings-долгом (`plan.md` §7)

| L-nn | Операции каталога |
|:--|:--|
| L-01 | OP-07 (S3-multipart complete — весь файл в RAM) |
| L-02 | OP-44 (SweepUploadsCommand `->get()` без limit) |
| L-03 | OP-46 (BlobGarbageCollector `candidates()` без batch-cap) |
| L-04 | OP-42 (WebhookRetryDispatcher::claimDue() без limit) |
| L-05 | OP-40 (OutboxRelay::subscribedEndpoints() O(endpoints)) |
| L-06 | OP-12, OP-13, OP-14, OP-20 (candidateNodeIds N-CTE / MoveNodeAction O(subtree)) |
| L-07 | Не привязан к конкретному OP — CAS-лок (`BlobLock`) срабатывает внутри commit-пути CAS-дедупа, задействованного из OP-02/OP-06/OP-07/OP-17/OP-27/OP-31 (любая операция, коммитящая blob); отдельной операции не заводится, т.к. лок — общий механизм, не наблюдаемый вход |
| L-08 | OP-44 (SweepUploadsCommand — не в расписании пакета, хост-долг; см. также RelayOutboxCommand/RetryWebhooksCommand/GcBlobsCommand — то же замечание применимо ко всем artisan-командам §8–§9) |

Новых находок сверх уже известных L-01…L-08 при обходе не всплыло.

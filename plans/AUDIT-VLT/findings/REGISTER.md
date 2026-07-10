# REGISTER — сводный реестр находок AUDIT-VLT

Append-only. Каждый audit-item дописывает свои находки СЮДА одной строкой на находку,
сразу после создания своего `findings/<Pn.m>-<slug>.md`. Существующие строки не править.
Сводный отчёт P5.1 строится ИЗ этого файла.

| ID | Item | Severity | Уверенность | Заголовок | Где (`file:line`) | Норма |
|:--|:--|:--|:--|:--|:--|:--|
| — | P0.1 | — | — | *(инвентаризация — находок по определению нет)* | — | — |
| — | P0.2 | — | — | *(RAG-добивка — вердикты о коде выносят P1–P4)* | — | — |
| — | P0.3 | — | — | *(карта поверхности — вердикты в P1)* | — | — |
| F-P1.1-01 | P1.1 | Major | high | Докблок объявляет «un-overridable wall», но deny на `inherit_grants=false` пробивается более близким allow | `AccessResolver.php:291-301` | код ≠ докблок H.2 (Major); S1.4 |
| F-P1.1-02 | P1.1 | Minor | high | Нет механизма «explain access» (какой ACE дал вердикт) | `AccessResolver.php:73-115` | S1.7 |
| F-P1.2-01 | P1.2 | Minor | high | Gate/Azgard-драйверы молча игнорируют v_node_grants, адресованные ролям/группам | `GatePermissionResolver.php:102-109` | S1.2; ADR-0007 |
| F-P1.2-02 | P1.2 | Nit | high | Docblock `GatePermissionResolver::gateAllows` обещает несуществующий catch | `GatePermissionResolver.php:121-131` | docblock ≠ код |
| F-P1.3-01 | P1.3 | Major | high | Скачивание редиректит на presigned-URL бакета в обход PHP (нарушение D15) | `DownloadController.php:104-111` | D15; S4.3/S4.4 |
| F-P1.3-02 | P1.3 | Major | medium | Issuer-права не перепроверяются при потреблении ссылки (confused deputy) | `ShareResolver.php:49-92` | S2.2 |
| F-P1.3-03 | P1.3 | Major | high | Ссылки без явного expiry не истекают никогда (нет дефолтного TTL) | `config/vaulter.php:448-452` | S4.1 |
| F-P1.3-04 | P1.3 | Minor | medium | `Content-Type` скачивания из пользовательских метаданных без whitelist и без `nosniff` | `DownloadController.php:144-147` | C13; G4 (nosniff) |
| F-P1.3-05 | P1.3 | Minor | high | Публичный `SharedNodeResource` отдаёт сырой ключ узла; при bigint — последовательный id | `SharedNodeResource.php` | S3.3 |
| F-P1.4-01 | P1.4 | Blocker | high | Гонка offset без блокировки → тихая порча файла | `TusUploadHandler.php:80-108` | U2.1/U2.2 |
| F-P1.4-02 | P1.4 | Major | high | offset-mismatch отдаёт 302/422 вместо tus 409 | `TusUploadHandler.php:90-96` | U1.3/U2.4 |
| F-P1.4-03 | P1.4 | Major | high | S3 complete коммитит усечённый файл без сверки total bytes | `S3MultipartHandler.php:132-153` | U6.7/U5.4 |
| F-P1.4-04 | P1.4 | Major | high | Нет пер-чанк лимита; append читает весь staged-файл в память | `StagedUpload.php:93-115` | U3.3/U3.4 |
| F-P1.4-05 | P1.4 | Minor | high | PATCH принимает неверный Content-Type вместо 415 | `TusUploadController.php:132-145` | U1.2 |
| F-P1.4-06 | P1.4 | Minor | high | Входящий `Tus-Resumable` не требуется | `TusUploadController.php` | U1.1 |
| F-P1.4-07 | P1.4 | Minor | high | Нет OPTIONS-дискавери и необъявленное отсутствие расширений | `drive.php:62-71` | U1.5/U1.6 |
| F-P1.4-08 | P1.4 | Minor | high | Метаданные без whitelist ключей и лимитов длины | `CreateUploadSessionRequest.php:36` | U4.3/U4.4 |
| F-P1.4-09 | P1.4 | Minor | high | Нет rate-limit / капа активных сессий | `drive.php:59-72` | U3.4 |
| F-P1.4-10 | P1.4 | Minor | medium | Застрявшие Assembled-сессии и осиротевшие части не сметаются | `SweepUploadsCommand.php:30-42` | U5.3/U6.6 |
| F-P1.4-11 | P1.4 | Minor | medium | Нет валидации mime/magic-bytes собранного объекта | `CommitUploadAction.php:96-120` | U5.5 |
| F-P1.4-12 | P1.4 | Minor | medium | Право на сессию не привязано к владельцу (IDOR-by-ability) | `TusUploadController.php:237-253` | S3.1; U6.4 |
| F-P1.4-13 | P1.4 | Minor | medium | Жизненный цикл сессии не пишется в activity | `UploadSessionManager.php` | U7.1 |
| F-P1.5-01 | P1.5 | Major | high | Промоушен пишет прямо в финальный CAS-ключ без temp+rename: частичный объект портит CAS | `FlysystemStorageDriver.php:34-53` | U5.1; ADR-0006 |
| F-P1.5-02 | P1.5 | Minor | high | `sweepPending` на PG не использует SERIALIZABLE / retry на 40001 | `BlobGarbageCollector.php:158-196` | C9 |
| F-P1.5-03 | P1.5 | Minor | high | dedup-UNIQUE(tenant_id, sha256) не срабатывает для NULL-tenant при scope=tenant | `PanelTablesBuilder.php:273` | ADR-0012; C15 |
| F-P1.5-04 | P1.5 | Nit | medium | Модель `Blob` полностью unguarded (`$guarded = []`) | `Blob.php:54` | U4.1; S3 |
| F-P1.6-01 | P1.6 | Major | high | Realtime-collab не отделён собственным фиче-флагом (нарушение D13) | `documents.php:42` | D13; C18 |
| F-P1.6-02 | P1.6 | Major | medium | Входящий collab-webhook без подписи тела и без защиты от replay | `CollaborationCallResolver.php:31-78` | C3.1/C3.3/C3.4 |
| F-P1.6-03 | P1.6 | Minor | medium | Нет периодической проверки `exp`/ACL в течение живого collab-соединения | `hocuspocus-server/src/index.js:98-147` | C5.1/C5.5 |
| F-P1.7-01 | P1.7 | Blocker | high | Исходящие webhook без SSRF-защиты (endpoint-URL — управляемый пользователем примитив) | `RegisterWebhookEndpointRequest.php:27` | U6.1; C1 |
| F-P1.7-02 | P1.7 | Major | high | `signing_secret` хранится в открытом виде | `PanelTablesBuilder.php:802` | C4; C3.5 |
| F-P1.7-03 | P1.7 | Major | medium | Outbox стейджится после коммита, а не в транзакции мутации (окно потери события) | `RecordsOutbox.php:26-38` | outbox-relay |
| F-P1.7-04 | P1.7 | Minor | high | Все три webhook-модели полностью незащищены (`$guarded = []`) | `WebhookEndpoint.php:39` | C13 |
| F-P1.7-05 | P1.7 | Minor | medium | Две команды re-dispatch над не-терминальными доставками → окно двойной доставки | `RelayOutboxCommand.php:48-60` | outbox-relay |
| F-P1.7-06 | P1.7 | Minor | medium | Размер тела ответа получателя не ограничен | `DeliverWebhook.php:96-108` | C11 |
| F-P1.8-01 | P1.8 | Major | high | Публичные маршруты без rate-limit | `packages/core/routes/api.php` | audit-gaps §1#2; C15 |
| F-P1.8-02 | P1.8 | Minor | high | `$guarded = []` во всех 16 моделях пакета | `Node.php:64` (+15 моделей) | C6; E7/E5 |
| F-P1.8-03 | P1.8 | Minor | high | LIKE-инъекция wildcard в поиске по имени узла | `NodeReadRepository.php:176` | C8 |
| F-P1.8-04 | P1.8 | Minor | medium | Нет гайда по CORS для REST-поверхности пакета | `docs/reference/rest-api.md` | audit-gaps §1#6; C16 |
| F-P1.8-05 | P1.8 | Minor | high | Reference docker-compose хаба тянет образ по тегу `latest` | `hocuspocus-server/docker-compose.yml:12` | C5; P4.4 |
| F-P1.9-01 | P1.9 | Minor | medium | `vaulter.tenant.enabled` — fail-open footgun: колонка `tenant_id` без runtime-гейта | `config/vaulter.php:219-222` | S3.2 |
| F-P1.9-02 | P1.9 | Minor | high | Нет теста, фиксирующего контракт делегированной tenancy | `packages/core/tests/**` | C11 |
| F-P1.10-01 | P1.10 | Major | high | Четыре из пяти ресурсов панели не скоупят листинг: чтение ACL/share/activity/storage всех тенантов | `StorageResource.php` (+3 ресурса) | S3.2/S1.6; P1.9 C3 |
| F-P1.10-02 | P1.10 | Minor | medium | `PanelActor` берёт глобальный дефолтный guard, а не guard панели (рассинхрон субъекта) | `PanelActor.php:28` | S1.6 |
| F-P1.10-03 | P1.10 | Minor | medium | Ресурсы панели не гейтятся на core feature-флаги (ActivityResource бьёт в отсутствующую таблицу) | `VaulterPlugin.php:78-108` | D13; P1.6 C18 |
| F-P1.11-01 | P1.11 | Blocker | high | Серверный TipTap-рендер не фильтрует схему URL в `href` (stored XSS) | `TipTapRenderer.php:93` | G2.2/G2.4 |
| F-P1.11-02 | P1.11 | Major | high | `TipTapContent` валидирует только корневой узел, не типы узлов и значения атрибутов | `TipTapContent.php:26-33` | G2.1/G2.3 |
| F-P1.11-03 | P1.11 | Major | high | Download- и share-пути не ставят `X-Content-Type-Options: nosniff` | `DownloadController.php:144-147` | G3.1/G3.2 |
| F-P1.11-04 | P1.11 | Minor | high | Markdown-экспорт протаскивает сырой HTML и `javascript:`-ссылки | `TipTapRenderer.php:165-181` | G2.4/G2.2 |
| F-P1.11-05 | P1.11 | Minor | high | `max_document_size_kb` не применяется при сохранении документа | `TipTapContent.php:63-72` | ADR-0004 |
| F-P1.12-01 | P1.12 | Major | high | Узел в корзине остаётся скачиваемым и экспортируемым (trash не влияет на авторизацию) | `VaulterServiceProvider.php:195-199` | G4.1; S3.1; C1 |
| F-P1.12-02 | P1.12 | Major | high | Force-delete/purge никогда не удаляет строки физически: контент версий остаётся в БД навсегда | `ForceDeleteNodeAction.php:41-72` | G4.2; ADR-0006 |
| F-P1.12-03 | P1.12 | Minor | high | `keep_forever` невозможно снять: закреплённая версия никогда не попадёт в prune | `DocumentNodeController.php:137-149` | G4.2; C6 |

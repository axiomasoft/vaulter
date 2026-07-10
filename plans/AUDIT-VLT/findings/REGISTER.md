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
| F-P2.1-01 | P2.1 | Minor | high | Докблок фасада не покрывает 5 из 12 публичных методов манагера | `Facades/Vaulter.php:11-17` | P2.1 C3 |
| F-P2.1-02 | P2.1 | Major | high | Reference-доки описывают публичный API, удалённый ADR-0014 | `docs/reference/facade.md:36,109-126`; `docs/reference/contracts.md:38-53,285-304` | P2.1 C13 |
| F-P2.1-03 | P2.1 | Minor | high | `docs/reference/contracts.md` не перечисляет 7 существующих публичных контрактов | `docs/reference/contracts.md:15-32` | P2.1 C13 |
| F-P2.1-04 | P2.1 | Nit | high | Namespace `UploadProtocolHandler` в доке не совпадает с кодом | `docs/reference/contracts.md:419-442` vs `UploadProtocolHandler.php:5` | P2.1 C13 |
| F-P2.1-05 | P2.1 | Minor | high | Статический реестр-кеш вне фасада | `EnumCaseAttributeResolver.php:18,26-43` | P2.1 C7 / E3.3 |
| F-P2.1-06 | P2.1 | Major | high | Split PHP-пакеты (Packagist-артефакт) не получают ни один CHANGELOG | `.github/workflows/monorepo-split.yml:36-41`; `.changeset/config.json:6,9` | P2.1 C10; E4.4 |
| F-P2.1-07 | P2.1 | Minor | high | Политика депрекации не объявлена | `ReverbNodeCollaborationDriver.php:16`; `config/vaulter.php:118,559` | P2.1 C12 / E4.4 |
| F-P2.2-01 | P2.2 | Major | high | `VaulterDocumentsServiceProvider` обходит `mergeConfigFrom`, читает файл с диска на каждый запрос | `VaulterDocumentsServiceProvider.php:59-73` | PT4.2; service-provider.md |
| F-P2.2-02 | P2.2 | Major | high | `runsMigrations()`+`discoversMigrations()`: публикация после автозапуска ведёт к повторному прогону под новым именем | `VaulterServiceProvider.php:39-40`; `docs/guide/installation.md:75-80` | PT2.2 |
| F-P2.3-02 | P2.3 | Major | high | Соединение БД (`connection`) не параметризовано: таблицы Vaulter нельзя вынести в отдельную БД | `Schema/PanelTablesBuilder.php`; `Models/Drive/Node.php` | E2.3 |
| F-P2.3-04 | P2.3 | Minor | high | Политика изменения схемы для будущих версий не объявлена | `docs/guide/database-compatibility.md` | P2.3 C12 |
| F-P2.4-01 | P2.4 | Major | high | Документация advertises мёртвый config-ключ `permission_resolver`; реальный хост (FlexCRM) написал нерабочую интеграцию против него | `docs/guide/configuration.md:189`; `config/vaulter.php` (ключ отсутствует) | P2.4 C9/C10 |
| F-P2.5-01 | P2.5 | Major | high | `vaulter:prune-webhooks` полностью отсутствует в `docs/reference/commands.md` | `docs/reference/commands.md:9-24`; `PruneWebhooksCommand.php:1-58` | P0-audit-gaps §1#13 |
| F-P2.5-02 | P2.5 | Major | high | `docs/reference/rest-api.md` пропускает 5 реальных маршрутов Drive-поверхности (`shared`, `nodes/{node}/comments` ×4) | `docs/reference/rest-api.md` | P0-audit-gaps §1#13; P2.5 C8 |
| F-P2.5-03 | P2.5 | Major | high | 9 из 15 ссылок «Docs:» в исключениях ведут на несуществующие якоря `docs/guide/errors.md`; один документированный якорь — для удалённого класса | `VaulterException.php:14-17`; `docs/guide/errors.md:1-49` | P2.5 C9 |
| F-P2.5-04 | P2.5 | Minor | high | README формулирует `vaulter:install` как «publishes config + migrations, runs them», хотя миграции только предлагается запустить | `README.md:53` | — |
| F-P2.5-05 | P2.5 | Minor | high | `gc.orphan_grace_hours` не задокументирован в `docs/reference/configuration.md` | `config/vaulter.php:405-408`; `docs/reference/configuration.md:283-287` | P2.5 C7 |
| F-P2.5-06 | P2.5 | Major | high | README рекламирует пять фиче-флагованных возможностей как готовые к использованию, не упоминая, что все пять выключены по умолчанию | `README.md:17-38`; `config/vaulter.php:171-198` | P2.5 C10; E7.1 |
| F-P2.5-07 | P2.5 | Major | high | `@vaulter/vue`: `yjs` и `@tiptap/pm` в `dependencies` вместо `peerDependencies` (dual-instance риск); `version: "0.0.0"` не готова к публикации | `packages/vue/package.json:3,51-57` | P2.5 C12 |
| F-P2.5-08 | P2.5 | Minor | high | `CONTRIBUTING.md` заявляет «PHPStan (level 8)», а `phpstan.neon` задаёт `level: max` | `CONTRIBUTING.md:29`; `phpstan.neon:5` | P2.5 C13 |
| F-P3.1-01 | P3.1 | Minor | high | Имя-политика узла (`DriveNameRules`) захардкожена, кастомизация только форком | `packages/core/src/Support/DriveNameRules.php:14-38` | E5.4 |
| F-P3.1-02 | P3.1 | Nit | high | Сообщение валидатора имени узла — хардкод-строка, не lang-ключ | `packages/core/src/Support/DriveNameRules.php:33-38` | E5.4 |
| F-P3.1-03 | P3.1 | Minor | high | `StorageAdapter`/`storage_adapter` — заявленный шов мёртв, реальная CAS-persistence идёт мимо него | `packages/core/src/Contracts/Cas/StorageAdapter.php:12-24`; `Support/DefaultStorageAdapter.php:13-29`; `config/vaulter.php:357`; `Support/Config.php:833-839` | E7.1 |
| F-P3.1-04 | P3.1 | Minor | high | Имя экспортированного файла централизовано (`NodeExportService::filenameFor`), но не является швом расширения | `packages/documents/src/Documents/NodeExportService.php:28,86-92` | E5.4 |
| F-P3.2-01 | P3.2 | Major | high | «Validated on boot» не соответствует фактическому ленивому резолву `PanelRegistry` | `packages/core/src/VaulterServiceProvider.php:56-66,113-118`; `Panels/PanelRegistry.php:93-114` | ADR-0009; E7.3 |
| F-P3.2-02 | P3.2 | Major | high | Хуки `fillNode()`/`presentNode()`/`pipes()` панели объявлены, но нигде не вызываются | `packages/core/src/Panels/StoragePanelProvider.php:34-65` | ADR-0009; E5.2 |
| F-P3.3-01 | P3.3 | Minor | high | Асимметрия шва handler↔exporter: нет tagged-источника/null-object у экспортёра, `registerExporter` не принимает FQCN | `packages/core/src/VaulterManager.php:142-193`; `packages/documents/src/Documents/DocumentHandlerRegistry.php:266-280` | E3.1 |
| F-P3.3-02 | P3.3 | Minor | high | Докблоки `HandlerRegistry`/`NodeHandlerResolver` заявляют «fails loudly с install hint», которого не существует в коде | `packages/core/src/Contracts/Documents/HandlerRegistry.php:13`; `NodeHandlerResolver.php:21-23`; `VaulterManager.php:142-145`; `Http/Resources/Drive/NodeResource.php:122-129` | E5.3 |
| F-P3.5-01 | P3.5 | Minor | high | Кросс-пакетные швы (CAS, HandlerRegistry, DriveHandleFactory) падают громко, но без install-hint | `packages/core/src/VaulterManager.php:75,142,153` | E5.3; E5.4 |
| F-P3.6-01 | P3.6 | Major | high | Install-гайд надстройки (collab) противоречит коду и сам себе о дефолтном транспорте; ADR-0001 устарел | `packages/core/config/vaulter.php:627-642,653`; `Support/Config.php:713-718`; `docs/guide/collaboration.md:30,38-43,52`; `docs/guide/hocuspocus-server.md:6-8`; `docs/adr/0001-collaboration-transport.md:15-16` | D13/D14; E7.1 |
| F-P4.1-01 | P4.1 | Minor | high | Purge корзины сканирует `v_nodes` без индекса на `trashed_at` | `NodeReadRepository.php:203-219`; `PanelTablesBuilder.php:177-223` | P4.1 C5 |
| F-P4.1-02 | P4.1 | Nit | high | Избыточные индексы с общим префиксом (`v_nodes`, `v_blobs`, `v_activity`) | `PanelTablesBuilder.php:210-211,248,277,740,873,883-888` | P4.1 C7 |
| F-P4.2-01 | P4.2 | Major | high | Обход предков на пути авторизации не ограничен `withMaxDepth` | `AccessResolver.php:356-375`; `NodeReadRepository.php:143-146,154-163` | P4.2 C1; ADR-0005 |
| F-P4.2-02 | P4.2 | Minor | high | Детект циклов выключен на чтении, митигирован только на записи через Action | `Models/Drive/Node.php:57-161`; `MoveNodeAction.php:85-100` | P4.2 C2 |
| F-P4.2-03 | P4.2 | Major | high | N+1 на каждой странице листинга узлов через `AccessResolver::abilitiesFor` | `NodeController.php:81-94`; `NodeResource.php:138-148`; `AccessResolver.php:73-115,404-425` | P4.2 C4 |
| F-P4.2-04 | P4.2 | Minor | high | `NodeController::shared()` — offset-пагинация на растущей кросс-storage ленте | `NodeController.php:109-137` | P4.2 C6 |
| F-P4.2-05 | P4.2 | Minor | high | Нестабильные (неуникальные) ключи курсорной пагинации в трёх фидах | `NodeReadRepository.php:171-177,186-191`; `GrantReadRepository.php:59-65` | P4.2 C7 |
| F-P4.3-01 | P4.3 | Minor | high | Ни один пакет не запрещает импорт `App\*`/хоста | `packages/*/tests/Arch/ArchTest.php` | P4.3 C1; E5.1 |
| F-P4.3-02 | P4.3 | Minor | high | Нет arch-гейта на границу публичного API пакета (E3.1) | `packages/*/tests/Arch/ArchTest.php` | P4.3 C3; E3.1 |
| F-P4.3-03 | P4.3 | Major | high | Мутационный гейт `infection.json5` отключён (`minMsi`/`minCoveredMsi` = 0) | `infection.json5:9-10` | P4.3 C4 |
| F-P4.3-04 | P4.3 | Minor | high | Мутационный гейт покрывает только `packages/core/src`, остальные 4 PHP-пакета вне охвата | `infection.json5:3-5` | P4.3 C5 |
| F-P4.3-05 | P4.3 | Minor | high | `composer test:types` (100% type-coverage) объявлен, но не подключён ни к одному CI-гейту core | `composer.json:60`; `.github/workflows/tests.yml` | P4.3 C6 |
| F-P4.4-01 | P4.4 | Minor | high | Rector не запускается ни в одном CI-workflow | `.github/workflows/code-style.yml`; `Makefile:16-20,25,32`; `rector.php:10-14` | P4.4 C2 |
| F-P4.4-02 | P4.4 | Major | high | CI-матрица Laravel 12/13 покрывает только `vaulter-core`; Filament не тестируется ни против одной ячейки честно | `.github/workflows/tests.yml:15-24`; `filament.yml:33-35`; `packages/filament/composer.json:29` | P4.4 C3/C4; `P4-matrix-supply-chain.md` §M3 |
| F-P4.4-03 | P4.4 | Minor | high | Политика поддерживаемых версий Testbench/Filament не объявлена | `README.md:10-15` | P4.4 C5; D12 |
| F-P4.4-04 | P4.4 | Minor | medium | `composer audit`: CVE-2025-45769 в `firebase/php-jwt` (используется в собственном collab-JWT минтинге) | `composer.json:30`; `packages/documents/composer.json:29`; `HocuspocusNodeCollaborationDriver.php` | P4.4 C7 |
| F-P4.4-05 | P4.4 | Major | high | `npm audit --audit-level=high` в `packages/vue`: critical RCE в devDependency `happy-dom` | `packages/vue/package.json:58-68` | P4.4 C8 |
| F-P4.5-01 | P4.5 | Minor | high | `@tiptap/*` в `packages/vue` на устаревшем мажоре (2.x, текущий стабильный — 3.x) | `packages/vue/package.json:53-55`; `e2e/package.json:19-21` | P4.5 C3 |
| F-P4.5-02 | P4.5 | Minor | medium | hocuspocus-стек (`@hocuspocus/server`+extensions, `@hocuspocus/provider`) на устаревшем мажоре (2.x, актуальны v3/v4) | `hocuspocus-server/package.json:16-19`; `packages/vue/package.json:52` | P4.5 C5/C3 |
| F-P4.5-03 | P4.5 | Minor | high | e2e-стенд: `laravel`/`hub`/`vite` без healthcheck, `depends_on` без `condition: service_healthy` | `e2e/docker-compose.yml:22-64` | P4.5 C7 |

# 13 — Рабочие пакеты для плана исполнения

Этот файл — заготовка executable-плана (формат `plans/<ID>/plan.md` проекта). Каждый пункт атомарен по
смыслу, имеет ссылки на решения (D), находки (N/E), сценарии проверки (V, см.
[14-verification.md](14-verification.md)), критерии готовности и рекомендуемого исполнителя.

Общие правила исполнения (добавить в Execution Rules плана):

1. Один пункт — одна ветка/PR, Conventional Commits (рус.), гейт затронутых пакетов зелёный **до** коммита:
   `pint --test`, `phpstan analyse` (max), `pest` (+ arch), для Vue — `vue-tsc`, `vitest`, `vite build`.
2. Любое изменение публичной поверхности → обновлённый `PublicApiSnapshotTest` golden в том же PR и запись в
   `UPGRADE.md` + `CHANGELOG.md [Unreleased]` (формат «было → стало», ссылка на D).
3. Переименования — по FQN-якорям (`php:namespace-refactor-safety`), не по голому токену (`Node` ⊂ `NodeGrant`).
4. Нельзя: пропускать/отключать тесты, `|| true` в CI, менять решения D без эскалации владельцу.
5. Если код расходится с этим досье — остановиться и описать расхождение (plan-protocol §10), не импровизировать.
6. Тесты конкурентности — только PostgreSQL (docker-compose сервис `pgsql` в CI и локально).

Маршрутизация: **opus** — контракты, алгоритмы доступа/конкурентности, миграции данных; **sonnet** — реализация
по готовой спецификации, механические переименования; **haiku** — документация по шаблону, чистка docblock'ов.

## Граф фаз

```
F0 Основа ──► F1 Исправления T0 (релиз 0.2.1) ──► F2 Канон имён и конфиг ──► F3 Прикладной слой ──┬─► F5 События
                                                                                                  ├─► F4 Модель данных и документы
                                                                                                  └─► F6 HTTP v1 и SDK (после F4, F5)
                                                                                                            └─► F7 Эксплуатация/DX ──► F8 Готовность 1.0
```

F4 и F5 можно вести параллельно после F3. F6 начинается, когда контракты F3–F5 заморожены.

---

## F0 — Основа

#### P0.1 — Зелёная базовая линия
- Сделать: прогнать полный текущий CI локально/в CI на `main`; зафиксировать фактический статус в `plans/<ID>/findings/baseline.md`.
- Готово: все существующие suites зелёные или каждое падение описано как отдельный пункт.
- Исполнитель: sonnet/low.

#### P0.2 — Стенд конкурентности PostgreSQL
- Сделать: `packages/core/tests/Concurrency/` — помощник, запускающий две операции на **двух разных соединениях**
  с барьером (advisory lock или `pcntl_fork` + файл-барьер), сервис PostgreSQL в CI; пример-тест «два UPDATE одной строки».
- Готово: пример проходит в CI; документировано в `CONTRIBUTING.md`.
- Исполнитель: sonnet/medium. Нужен для V02, V03, V13, V20, V21.

#### P0.3 — Фикстура данных 0.2
- Сделать: скрипт `scripts/fixtures/make-v0.2-dump.php`, который на теге `v0.2.0` создаёт БД с drives (bigint/ulid),
  файлами с версиями, документами (все 4 `format`), grants/shares (включая soft-deleted), activity, outbox,
  blobs в `snull`/`tnull`; дамп `tests/fixtures/v0.2.0/*.sql` для PG и MySQL.
- Готово: дамп воспроизводимо генерируется; README фикстуры описывает содержимое. Нужен для V15.
- Исполнитель: sonnet/medium.

#### P0.4 — ADR 0019–0024
- Сделать: ADR по шаблону `docs/adr/` из этого досье: 0019 Kernel ownership (D02), 0020 Authorized public API
  (D06–D09), 0021 Profiles (D05, amendment ADR-0009), 0022 Revisions & documents aggregate (D15–D17, amendment
  ADR-0018), 0023 Event pipeline (D22–D23), 0024 REST v1 & errors (D29–D31). Индекс ADR обновить.
- Готово: ADR в статусе `proposed`, владелец переводит в `accepted`.
- Исполнитель: opus/medium (перенос, не переосмысление).

---

## F1 — Исправления T0 (на текущих именах; кандидат 0.2.1)

#### P1.1 — Соединение Vaulter для транзакций и блокировок
- Решения: D25 · Находки: N01 · Сценарии: V17.
- Сделать: `Vaulter\Database\VaulterDatabase` (08 §4); заменить все `DB::transaction/connection/afterCommit` в
  `src` всех пакетов; `BlobLock`, GC, reconciler, relay, retry, `UploadSessionStoreRepository`; arch-тест запрета фасада `DB`.
- Готово: V17 зелёный на PG (выделенное соединение ≠ default); все прежние тесты зелёные.
- Исполнитель: sonnet/medium; ревью vaulter-reviewer.

#### P1.2 — Инварианты дерева
- Решения: D13 · Находки: N04, N05 · Сценарии: V18, V19.
- Сделать: проверка «родитель/назначение — активная папка того же storage» во всех Actions создания/перемещения/копирования/восстановления;
  защита корня; `lockTree` + проверка цикла внутри транзакции move; `CreateDocumentNodeAction` с `parent = null` кладёт
  под корень; миграция: перенос существующих «вторых корней» под корень + уникальный индекс единственного корня;
  нормализация имён (`NodeName`).
- Готово: V18, V19 зелёные; новые исключения `InvalidDestinationException` с render 409.
- Исполнитель: opus/high (миграция данных + блокировки), затем sonnet.

#### P1.3 — Точная видимость листинга и поиска
- Решения: D12 · Находки: N08, E10 · Сценарии: V09, V22.
- Сделать: вынести свёртку в `AclFolder`; `AccessResolver::evaluateMany()` (09 §2); листинг детей, поиск, корзина,
  shared, Filament-таблица — через точный фильтр с курсором «последний просмотренный» (09 §3) для **всех**
  драйверов; `NodeResource::abilities` — из того же пакетного результата.
- Готово: property-тест эквивалентности `evaluateMany` ≡ `effectiveGrants` (≥ 500 случайных деревьев); V09, V22
  зелёные; число SQL-запросов на страницу 50 узлов ≤ 5 (тест счётчика запросов).
- Исполнитель: opus/high.

#### P1.4 — Идентичность событий в outbox
- Решения: D22 (часть) · Находки: E8 · Сценарии: V06.
- Сделать: каждое событие получает `eventId` (ULID) в конструкторе; `RecordsDriveOutbox`/`RecordsOutbox` используют его как
  `event_id`; state-ключи удаляются.
- Готово: V06 зелёный (A→B→A→B даёт 3 строки; trash→restore→trash даёт 3).
- Исполнитель: sonnet/medium.

#### P1.5 — Беспотерьная область CAS
- Решения: D20 · Находки: E1, N02 (часть blobs) · Сценарии: V01, V13, V14.
- Сделать: `BlobScope`, `scope_key` + миграция заполнения + `UNIQUE(scope_key, sha256)`; сигнатуры `BlobStore`/`ContentStore`
  без `int`; `UploadFileAction` передаёт строковый scope; reconciler узнаёт старые/новые/UUID-ключи;
  `vaulter:blobs:repair-scopes --dry-run|--execute` (media).
- Готово: V01 для bigint/ulid/uuid7 × scope global/tenant/storage; V13, V14 зелёные.
- Исполнитель: opus/high.

#### P1.6 — Атомарная квота и счётчик ревизий
- Решения: D21 · Находки: E2 · Сценарии: V02, V03, V20.
- Сделать: условный UPDATE `used_bytes` внутри транзакции; `FOR UPDATE` строки файла; номер ревизии = `version_count + 1`;
  delta от перечитанной головы; ретраи в `VaulterDatabase::transaction`.
- Готово: V02, V03, V20 зелёные на PG.
- Исполнитель: sonnet/high (по спецификации 08 §5).

#### P1.7 — Типы колонок ключей хоста
- Решения: D25 · Находки: N02 · Сценарии: V23.
- Сделать: `ids.host_keys` (по умолчанию `string`), `HostKeyColumns` в `SchemaBuilder` для owner/subject/tenant/actor-колонок;
  upgrade-миграция изменения типов (PG/MySQL); `VaulterSchema::hostKey()`.
- Готово: V23 (хост с UUID-пользователями и bigint-проектами при `ids.strategy=ulid`) зелёный.
- Исполнитель: sonnet/high; миграция — ревью opus.

#### P1.8 — Нет HTTP 500 для доменных ошибок
- Решения: D29 (часть) · Находки: N11 · Сценарии: V24.
- Сделать: у каждого исключения Vaulter — `httpStatus()` и `problemCode()`; временный рендер в текущем конверте + поле `code`;
  тесты, ожидавшие 500, переписать на корректные статусы.
- Готово: V24 — ни одно доменное исключение не даёт 500.
- Исполнитель: sonnet/low.

#### P1.9 — Стеки middleware и отказ от глобальных биндингов
- Решения: D31 (часть) · Находки: N09, N10 · Сценарии: V25, V26.
- Сделать: `routes.middleware.{authenticated,public,hub}` (с legacy-ключом `middleware` → authenticated); публичные share
  и collab-колбэки — в свои стеки; удалить `Route::bind('node'|'storage')`, резолвить через `HttpResourceResolver`.
- Готово: V25 (хост добавляет `auth:sanctum`, публичные ссылки и хаб работают), V26 (маршрут хоста `{node}` не задет).
- Исполнитель: sonnet/medium.

#### P1.10 — Честные зависимости и авторизация Filament
- Решения: D02 · Находки: N14, N15 · Сценарии: V27.
- Сделать: `vaulter-documents` и `vaulter-filament` `require` `vaulter-drive`; в Filament move проверяет `Create` на
  назначении (временно через `AccessResolver`, до F3); README-строки о независимости удалить.
- Готово: V27 зелёный; consumer-установка documents без явного drive подтягивает drive.
- Исполнитель: sonnet/low.

#### P1.11 — Срочная правка README и metadata
- Находки: N20, audit P0.
- Сделать: README — корректный quick start **текущего** 0.2 API (`Vaulter::for`, `HostRoleDriver`); URLs `axiomasoft` в
  composer/npm/`VaulterException::docs()`/install command.
- Готово: все ссылки открываются; пример README исполняется как recipe-тест.
- Исполнитель: haiku/low.

Выпуск **0.2.1** после F1 (по решению владельца, 15-owner-questions Q1).

---

## F2 — Канон имён и конфигурации (T1, начало 0.3)

#### P2.1 — Переименования core + миграции таблиц
- Решения: D03, D04, D05, D11 (удаление PermissionLevel) · Карта: 03 §3, §6, §7.
- Сделать: Storage→Drive, FileVersion→FileRevision, Panels→Profiles (с удалением own-tables и мёртвых хуков),
  HostRoleDriver→PermissionDriver (+ AccessScope), Engines→Handlers, `WebhookEvent`+`ActivityVerb`→`EventType`;
  миграции 2, 4, 8, 9 из 08 §3 (переименования, cleanup grants/shares); upgrade-preflight (миграция 1).
- Готово: все suites core зелёные; golden API обновлён; V15 (частично: миграции проходят на фикстуре P0.3).
- Исполнитель: sonnet/high, по пакету за PR; ревью vaulter-api-surface + vaulter-reviewer.

#### P2.2 — Переименования и раскладка drive
- Карта: 03 §4, 04 §4. Сделать: классы, domain-подпапки (снимает `->todo()` ADR-0017 Law 1), команды.
- Исполнитель: sonnet/medium.

#### P2.3 — Переименования documents (без реестра типов)
- Карта: 03 §5 (кроме Types/Content — это F4). Сделать: Versions→Revisions, domain-подпапки (Law 2 `->todo()` снимается).
- Исполнитель: sonnet/medium.

#### P2.4 — AzGuard из core в vaulter-azgard; media/filament
- Решения: D02 п.5 · Находки: N21. Сделать: перенос контракта/драйвера/карты/фейка; регистрация ключа `azgard`;
  переименования media-команд; Filament `StorageResource`→`DriveResource`.
- Готово: arch-тест «core не содержит Azgard»; тесты azgard зелёные.
- Исполнитель: sonnet/medium.

#### P2.5 — Канонический конфиг и типизированный доступ
- Решения: D26 · Карта: 07. Сделать: новые файлы конфигов по пакетам; `ConfigNormalizer` (07 §6) с тестом на каждый
  устаревший ключ; `Configuration\*Config` классы; `ConfigValidator` (07 §7); удалить `Support\Config`; arch-тест запрета
  `config('vaulter…')` вне `Configuration`.
- Готово: V28 (опубликованный конфиг 0.2 работает с предупреждениями; конфликт ключей — исключение).
- Исполнитель: sonnet/high.

#### P2.6 — Типы колонок, связанные с переименованием
- Сделать: убедиться, что все новые/переименованные колонки HK/REF соответствуют 08 §2; doctor-заглушка проверки типов.
- Исполнитель: sonnet/low.

---

## F3 — Прикладной слой: авторизованный публичный API (T0/T1)

#### P3.1 — Actor, контекст, guard
- Решения: D07, D09 · Спецификация: 05 §7, 09 §1.
- Сделать: `Actor`, `ActorRef`, `OperationContext`, `MissingActorException`, `NodeNotFoundException`, `OperationGuard`
  (без tenancy — точка расширения), `NodeLocator`.
- Готово: unit-тесты алгоритма 09 §1 на всех ветках.
- Исполнитель: opus/high.

#### P3.2 — Контракты handle и типы данных
- Решения: D06 · Спецификация: 05 §3–§6.
- Сделать: интерфейсы `DriveHandle`, `NodeOperations`, `FolderOperations`, `FileOperations`, `TrashOperations`,
  `AccessOperations`, `ShareOperations`, `ActivityOperations`; `UploadSource`, `*Options`, `NodePage`, `FileStream`,
  `OnNameConflict`, `NodeSort`; `VaulterManager::drive()/findDrive()/profiles()/handlers()/permissions()/withoutTenancy()`.
- Готово: golden API соответствует 05; удалены старые методы фасада.
- Исполнитель: opus/high.

#### P3.3 — Реализации drive Operations и новые сигнатуры Actions
- Решения: D08, D13 · Сценарии: V04, V27.
- Сделать: `Vaulter\Drive\DriveHandle` (иммутабельный), `DriveHandleFactory`, `DriveProvisioner`, Operations (05 §4 таблица
  способностей), все Actions → `execute(OperationContext, *Input)`; `OnNameConflict::Rename`; `extension()`.
- Готово: V04 (Reader через PHP = отказ, как через HTTP), тесты каждой строки таблицы способностей.
- Исполнитель: sonnet/high; ревью opus.

#### P3.4 — Core Operations: доступ, шаринг, activity
- Сделать: реализации, `explain` только с `Manage`, grants удаляются физически, share `commenter` запрещён.
- Исполнитель: sonnet/medium.

#### P3.5 — Модель прав: бит Comment, драйвер по профилю
- Решения: D11 · Находки: N07, N12 · Сценарии: V29.
- Сделать: `Permission::Comment`, биты уровней, `Ability::Comment`; `AccessResolver` берёт драйвер из профиля;
  удалить `Permission::All`; исправить тест `NodeCommentApiTest`.
- Готово: V29; контрактный тест драйверов обновлён.
- Исполнитель: sonnet/medium.

#### P3.6 — Профили как runtime-политика
- Решения: D05 · Спецификация: 05 §8.
- Сделать: builder `Profile` (поля 05 §8), `UploadPolicy`, `ProfileRegistry` (freeze), применение: владельцы при provisioning,
  диск, квота по умолчанию, grants/shareLinks/comments, типы документов, лимит ревизий, ретеншн корзины;
  `vaulter:make:profile`.
- Готово: тест на каждый параметр профиля (разрешено/запрещено).
- Исполнитель: sonnet/high.

#### P3.7 — Единый ingest загрузок
- Решения: D18, D19 · Находки: E4, N16 (MIME) · Сценарии: V05, V16 (scan), V30.
- Сделать: `StagedUpload` из `UploadSource` (потоково, лимит во время чтения), `UploadFacts`, `MimeSniffer` (finfo),
  инспекторы, `IngestFileAction` как единственный путь (single, PHP, tus, S3), `declared_mime_type`, `scan_status`,
  `ContentScanner` шов + job, идемпотентный `complete`.
- Готово: V05 (одни и те же запрещённые байты отклонены всеми входами), V16 (scan pending → 423), V30 (non-seekable,
  PSR-поток без загрузки в память, файл хоста не закрыт пакетом).
- Исполнитель: opus/high (дизайн staging) → sonnet.

#### P3.8 — Strict tenancy
- Решения: D10 · Сценарии: V08, V31.
- Сделать: `TenantResolver`, boot-проверка, стемпинг при provisioning, шаг guard, фильтр `HttpResourceResolver`,
  job-middleware `RestoreTenantContext`, `Vaulter::withoutTenancy()`; удалить `TenantFootgunGuard`.
- Готово: V08, V31.
- Исполнитель: sonnet/high; ревью vaulter-security-review.

---

## F4 — Модель данных и документы (T1)

#### P4.1 — Агрегат Document и очистка узлов
- Решения: D16 · Находки: N03 · Сценарии: V32, V15.
- Сделать: `v_documents`, `DocumentRevision` (таблица/колонки 08 §2), миграции 5–6 из 08 §3; удаление
  `nodes.current_version_id`, `nodes.file_id`, `Node::currentVersion()`; головы только в агрегатах.
- Готово: V32 (bigint, документ и файл с одинаковыми id ревизий не путаются), V15 на фикстуре.
- Исполнитель: opus/high.

#### P4.2 — Содержимое по kind
- Решения: D17 · Находки: N06 · Сценарии: V33.
- Сделать: `NodeContentLifecycle`, `FileContentLifecycle`, `DocumentContentLifecycle`, диспетчеризация в Copy/Purge.
- Готово: V33 (копия документа с контентом; purge документа удаляет ревизии).
- Исполнитель: sonnet/medium.

#### P4.3 — Реестр типов документов
- Решения: D14, D15 · Находки: E5, N16 · Сценарии: V10, V34.
- Сделать: контракты 06 §3, `DocumentTypeRegistry`, codecs, встроенные типы, `UnknownDocumentType`, `NodePresenter` с
  presentation для документов; удалить `DocumentType` enum, `TipTapHandler`, `UniverHandler`, `registerDocumentType`;
  `ViewerDescriptor`; фасад `Documents`; `vaulter:make:document-type`.
- Готово: V10 (сторонний тип: установка → создание → удаление расширения → безопасный fallback → повторная установка),
  V34 (`.docx`-файл не объявлен редактируемым).
- Исполнитель: opus/high → sonnet.

#### P4.4 — Экспорт в documents
- Решения: D28. Сделать: контракт/реестр по (тип, формат), экспорт отдаёт файл.
- Исполнитель: sonnet/low.

#### P4.5 — Коллаборация: epoch и read-only
- Решения: D24 · Находки: N17 · Сценарии: V11, V12.
- Сделать: `collab_epoch`, epoch в токене/колбэках, 409 на store, изменения `hocuspocus-server`, read-only на сервере хаба,
  удаление Reverb-драйвера коллаборации (если Q7 не отменён).
- Готово: V11, V12 (включая интеграционный тест с реальным хабом в docker-compose).
- Исполнитель: opus/high.

#### P4.6 — Одна модель удаления и жизненный цикл drive
- Решения: D27 · Находки: N13, N22 · Сценарии: V35.
- Сделать: удалить `SoftDeletes` и колонки (миграции), `DriveStatus`, `ArchiveDriveAction`, `DeleteDriveAction`,
  `vaulter:drives:purge`, trait `HasDrives`, `drives.delete_with_owner`.
- Готово: V35 (удаление владельца с опцией → drive исчезает, байты уходят через GC).
- Исполнитель: sonnet/high.

#### P4.7 — Sequence и оптимистичная блокировка
- Решения: D23 · Сценарии: V36.
- Сделать: `v_nodes.sequence`, инкремент во всех мутациях, `expectedSequence`, `StaleNodeException`.
- Исполнитель: sonnet/medium.

---

## F5 — Конвейер событий (T1)

#### P5.1 — DomainEvent и EventRecorder
- Решения: D22 · Находки: E7, E8, N18 · Сценарии: V06, V07.
- Сделать: база `DomainEvent`, `EventRecorder` (activity + outbox в транзакции на соединении Vaulter; after-commit
  dispatch), удалить четыре подписчика и `ShouldDispatchAfterCommit`; `ActorRef`, `correlation_id`, `sequence` в событиях.
- Готово: V07 (сбой записи outbox откатывает мутацию; краш после commit не теряет событие), V06.
- Исполнитель: opus/high.

#### P5.2 — Полный каталог и конверт webhooks
- Сделать: события documents/comments/uploads/drive lifecycle (08 §6); конверт `id,type,source,time,drive_id,subject,sequence,actor,data`;
  заголовок `X-Vaulter-Event-Id`; `reference/events.md` генерируется.
- Исполнитель: sonnet/medium.

---

## F6 — HTTP v1 и клиенты (T1)

#### P6.1 — Контроллеры и маршруты v1
- Решения: D30, D31, D33 · Спецификация: 10 §1–§5.
- Сделать: invokable-контроллеры по таблицам 10 §4 поверх Operations; `HttpOperationContextFactory`; ресурсы без
  внутренних указателей; `ETag`/`If-Match`; `X-Vaulter-Api-Version`; старые маршруты удалить.
- Готово: snapshot маршрутов = таблица 10 §4; все feature-тесты переписаны на v1.
- Исполнитель: sonnet/high.

#### P6.2 — Problem Details
- Решения: D29 · Спецификация: 10 §6. Сделать: `ProblemException`, `ProblemRenderer`, коды, `lang/`-заголовки, `reference/errors.md`.
- Исполнитель: sonnet/medium.

#### P6.3 — OpenAPI и контрактные тесты
- Сделать: `openapi/vaulter-v1.yaml`, валидация ответов в feature-тестах, `oasdiff` job.
- Готово: каждый маршрут `vaulter.v1.*` описан; валидация без исключений.
- Исполнитель: sonnet/high.

#### P6.4 — `@vaulter/vue` на v1
- Решения: D32 · Спецификация: 11.
- Сделать: генерация типов, клиент по ресурсам, реестр viewer'ов на приложение, fallback-компонент, composables, a11y-тесты.
- Готово: `vue-tsc`, vitest, build; V37 (неизвестный viewer показывает fallback).
- Исполнитель: sonnet/high.

#### P6.5 — Хаб на v1
- Сделать: пути v1, epoch, read-only, тесты хаба, Docker build.
- Исполнитель: sonnet/medium.

#### P6.6 — Filament через handle
- Сделать: все действия и таблицы через `Vaulter::drive(...)->actingAs(PanelActor::current())`; удалить `PanelAccessScope`.
- Готово: V27; arch-тест «Filament не импортирует Actions/Repositories».
- Исполнитель: sonnet/medium.

---

## F7 — Эксплуатация и DX (T1)

| Пункт | Содержание | Спецификация | Исполнитель |
|---|---|---|---|
| P7.1 | `vaulter:doctor` + проверки всех пакетов | 12 §2 | sonnet/medium |
| P7.2 | `vaulter:install`, планировщик, переименование команд | 12 §1, §3 | sonnet/medium |
| P7.3 | Генераторы `vaulter:make:*` со стабами и тестами | 06 §12 | sonnet/low |
| P7.4 | Фабрики, `InteractsWithVaulter`, контрактные наборы | 12 §5 | sonnet/medium |
| P7.5 | Metapackage, split-проверки, consumer-установка из артефактов, AzGuard-тег | 12 §6–§7 | sonnet/high |
| P7.6 | Документация: структура, рецепты, генерация reference, README, UPGRADE 0.2→0.3, чистка docblock | 12 §8 | haiku/medium (по шаблонам) + sonnet ревью |

---

## F8 — Готовность 1.0

#### P8.1 — Прогон всех гейтов 12 §7
- Готово: все гейты зелёные на одном коммите; отчёт `plans/<ID>/findings/release-gates.md` с логами.

#### P8.2 — Upgrade на реальных данных
- Сделать: если у владельца есть установки 0.2 — прогон миграций и repair на копии их БД; иначе — на фикстуре P0.3.
- Готово: `vaulter:doctor --production` без ошибок; сценарии V15.

#### P8.3 — Заморозка
- Сделать: 1.0.0-rc.1; две недели без изменений публичного API (Roave BC пуст), затем 1.0.0.
- Исполнитель: vaulter-release-conductor + решение владельца.

## Сводка оценок

| Фаза | Пунктов | Относительный объём | Риск |
|---|---|---|---|
| F0 | 4 | S | низкий |
| F1 | 11 | L | средний (миграции, блокировки) |
| F2 | 6 | XL (механика) | средний (массовые переименования) |
| F3 | 8 | XL | высокий (граница безопасности) |
| F4 | 7 | L | высокий (миграция данных документов) |
| F5 | 2 | M | средний |
| F6 | 6 | L | средний |
| F7 | 6 | M | низкий |
| F8 | 3 | S | — |

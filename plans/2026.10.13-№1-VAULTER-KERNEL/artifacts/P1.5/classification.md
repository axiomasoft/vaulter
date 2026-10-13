# P1.5 — классификация тестов по слоям (database, serial, extended, stand, database-free)

Источник правды: `packages/*/tests/groups.php` и `load/tests/groups.php` (по одному файлу на набор, пути относительно каталога тестов).
`tests/Repo/TestGroupsTest.php` держит их истинными: пути существуют, `database[]` в `tests/zones.json` равен объединению, каждая
гонка/engine-only файл лежит внутри `database`, `serial`/`stand` дают `nofast`/`nofull`. Правило П4: классификация по поведению,
не по имени каталога; сомнение → `database`. Ниже — причина по каждой строке; каталог целиком — когда однороден.

Числа — файлы `*Test.php` по `tests/suites.json` (то, что реально запускает runner), не тест-кейсы.

| Набор | Файлов | `database` | `serial` | `engine:*` | `stand` | database-free (без схемы) |
|:--|--:|--:|--:|--:|--:|--:|
| core | 234 | 69 | 9 | 6 | — | 10 + 26 без TestCase |
| media | 31 | 20 | 1 | 2 | — | 2 + 4 без TestCase |
| drive | 140 | 34 | 16 | 15 | — | 14 |
| documents | 100 | 21 | 7 | 7 | — | 14 |
| azgard | 13 | 2 | — | — | — | — |
| filament | 21 | 0 | — | — | — | — |
| load | 19 | 0 | — | — | 19 | — |

## Критерий `database`

Тест — `database`, если его результат зависит от движка: DDL и миграции, типы id/host keys, коллации и регистр, JSON/bytea,
блокировки (`FOR UPDATE`, advisory, `SKIP LOCKED`), транзакции и commit/rollback-семантика, сырой SQL, пагинация и сортировка в SQL,
каскады FK, гонки на отдельных соединениях. Не `database`: тест ходит в БД только через Eloquent/репозиторий как в хранилище
(результат одинаков на любом движке), проверяет HTTP/конфиг/DTO/архитектуру/генерацию документации, либо вообще не поднимает БД.

## core — `database` (69 файлов)

| Файл / каталог | Группа | Причина |
|:--|:--|:--|
| `Schema/` (24) | database | DDL, индексы, уникальные ограничения, FK, LIKE-escaping, коллации, типы ключей — смысл теста и есть движок |
| `Concurrency/` (5) | database, serial, engine:server | fork-гонки на отдельных соединениях PG (`ForkRace`); на SQLite целиком пропускаются |
| `IdStrategy/` (11) | database | форма схемы под bigint/ulid/uuid7, ULID-листинг выше лимита биндов PG (65 540 строк) |
| `TablePrefix/`, `Ulid/` | database | префикс таблиц и ULID-режим меняют DDL |
| `HostKeyMysql/` | database | DDL host-ключей MySQL/MariaDB |
| `Database/VaulterDatabaseTest` | database, serial | транзакции, блокировки, fork на втором соединении (смешанный файл: часть тестов идёт и на SQLite) |
| `Changes/ChangeFeedReadTest`, `ChangeJournalTest`, `PruneChangesCommandTest` | database | журнал изменений: seq под head-локом, батчевое удаление, закоммиченные фикстуры (`DatabaseMigrations`) |
| `Events/EventRecorderTest`, `WebhookAutoDisableCommitTest` | database | запись события и commit/rollback внешней транзакции (закоммиченные фикстуры) |
| `Tenancy/TenantProvisioningRaceTest` | database, serial, engine:server | fork-гонка провижининга |
| `Tenancy/TenantIdentifierPredicateTest` | database | коллация идентификатора (NOCASE / utf8mb4_unicode_ci / PG) |
| `Sharing/ShareSecurityBoundaryTest` | database, serial | часть тестов — fork-гонки (`ForkRace`) на отдельных соединениях; смешанный файл |
| `Webhooks/ConcurrentDeliveryTest` | database, serial | два воркера через `pcntl_fork`, только PG |
| `Webhooks/OutboxFanoutTest`, `OutboxRelayTest`, `OutboxTransactionTest` | database | outbox: захват строк, чанкование, коммит вместе с доменной транзакцией |
| `Webhooks/WebhookSchemaTest` | database | DDL таблиц вебхуков |
| `Webhooks/SigningSecretEncryptionTest` | database | длина/тип колонки зашифрованного секрета |
| `Webhooks/WebhookPruneSafetyTest`, `Feature/PruneWebhooksTest` | database | переносимое батчевое удаление (`chunk:`), границы по времени |
| `Webhooks/WebhookRetryDispatcherTest`, `WebhookAutoDisableTest` | database | консервативно (П4, сомнение → database): SQL-выборка due-строк и обновление счётчиков сбоев; файлы не разбирались по строкам, кандидаты на пересмотр в P1.7 |
| `Access/AccessAwareListingTest`, `EvaluateManyEquivalenceTest`, `WideNodeListingTest` | database | листинг с `whereIn`-чанками и курсорами, лимит биндов, тип id |
| `Content/ScanEvidenceBoundaryTest` | database | уникальность/идемпотентность scan-work в транзакции |
| `Doctor/CoreDoctorChecksTest` | database | интроспекция схемы (drop таблиц/migrations, предупреждение о SQLite) |
| `Console/InstallCommandTest` | database | `vaulter:install --migrate` запускает миграции на соединении |
| `Unit/DatabaseSchemaReuseTest` | database | повторное использование серверной схемы (на SQLite пропускается) |

### core — не `database` (165 файлов), почему

`Arch/`, `Configuration/`, `Providers/`, `PublicApi/`, `Naming/`, `Profiles/`, `Recipes/`, `Testing/`, `Support/` — архитектура, конфиг, публичный
контракт, снимки API, гварды документации: ни SQL, ни DDL. `Http/` (24), `OpenApi/`, `Operations/`, `Activity/`, `ActivityGate/`, `Spatie/`,
`WriteGuard/`, `Observability*/` — HTTP-контракт, права, метрики: БД только через Eloquent, результат один на всех движках; счётчики
запросов (`AbilitiesManyTest`, `ChangesV1Test`) проверяют число/форму запросов, а не поведение движка. `Webhooks/` (11 остальных: подпись,
верификатор, SSRF-гард, эндпоинты, ротация секрета, redeliver) — криптография и HTTP. `Sharing/` (6 остальных), `Feature/` (11), `Tenancy/`
(2 остальных), `Events/` (4), `Changes/ChangeCursorTest` — доменная логика без SQL-специфики. Сомнительные случаи (`Sharing/ShareResolverTest` создаёт
таблицу через `Schema::create`, `Operations/NodeLocatorTest` держит вторую sqlite-связь) оставлены вне `database`: схема там вспомогательная,
утверждения от движка не зависят.

## media — `database` (20 из 31)

`Cas/` без `OrphanGraceConfigTest`: сборка мусора и сверка блобов на `FOR UPDATE`/advisory/`SKIP LOCKED`, коммит блоба в транзакции
(`BlobCommitAtomicityTest`, `BlobFindOrCreateTest`), `SERIALIZABLE`-ретраи (`SweepPendingSerializableTest`, engine:pgsql),
fork-гонка (`ScopeKeyReconcileTest`: database, serial, engine:server), сырой SQL и счётчики в `BlobGcBatchTest`,
`ColdMutationSurvivorTest`. Остальное (`Arch`, `Configuration`, `Console`, `Doctor`, `Feature`, `Observability`, `Recipes`) — не `database`.
Тест-уровневый `->group('serial')` в `BlobCommitAtomicityTest` (глобальный `sys_get_temp_dir()`-glob) получил `nofast`.

## drive — `database` (34 из 140)

| Файл / каталог | Группа | Причина |
|:--|:--|:--|
| `Concurrency/` (17) | database; 16 файлов serial (+engine:server у 15) | fork-гонки: квоты, ревизии, блокировки дерева, Tus PATCH vs sweep, архивация; `CompleteUploadRetryTest` не форкает → только database; `TusConcurrentPatchTest` форкает, но один его тест идёт и на SQLite → serial без engine |
| `Changes/DriveChangesEndToEndTest` | database | журнал на закоммиченных фикстурах |
| `Uploads/UploadSchemaTest`, `UploadSessionLockDriverTest`, `UploadSessionCriticalSectionTest`, `R07bSessionContractsTest`, `R07SessionManagerContractsTest` | database | схема и advisory/`FOR UPDATE`-локи сессии загрузки, DROP CONSTRAINT на PG |
| `Uploads/P3/CompleteUploadIdempotencyTest`, `ScanWorkRecoveryTest`, `UnifiedIngestTest` | database | коммит в транзакции, `afterCommit`, восстановление scan-work (ветвление по драйверу) |
| `Feature/PurgeTrashTest`, `TreeInvariantsTest`, `ExactListingTest`, `R07TusControllerContractsTest`, `QuotaReserveTest` | database | каскады FK, инварианты дерева на SQL, `DB::raw`-обновления, DROP CONSTRAINT, атомарный резерв квоты |
| `Drive/DrivePurgeTest` | database | каскадное удаление (ветка по драйверу) |
| `PublicApi/LifecycleBoundaryTest`, `NodeSequenceContractTest` | database | сырой SQL и лок последовательности узлов |

Тест-уровневые `->group('serial')` в `CompleteUploadIdempotencyTest` и `RevisionNumberRaceTest` получили `nofast`. Не `database`: `Arch`, `Configuration`,
`Http` (16), `Providers`, `Doctor`, `Events`, `Observability`, `Panels`, `Profiles`, `Recipes`, `Tenancy`, `Testing`, `WriteGuard`, остальные `Drive/`,
`Feature/` (20), `Uploads/` (18) — HTTP-контракт, права, конфиг, чистая доменная логика.

## documents — `database` (21 из 100)

`Concurrency/` (7, serial, engine:server) — fork-гонки состояния документа, комментариев, collab-fence; `AfterCommit/CommentAfterCommitTest` — событие
ждёт внешний commit; `Schema/` (2) — DDL агрегата и комментариев; `Document/DocumentHeadIntegrityTest`, `DocumentPurgeLifecycleTest`, `PurgeTrashCascadeTest`,
`DocumentTreeInvariantsTest`, `DocumentStateVersionTest`, `DocumentStateWriterTest` (`lockForUpdate`), `DocumentSchemaWritableTest`, `DocumentCopyLifecycleTest`
(`yjs_state` bytea) — целостность, каскады, локи, бинарное состояние; `Collaboration/CollaborationWebhookTest`, `HubProtocolTest` — round-trip бинарного
состояния за эпоха-fence; `Types/UnknownDocumentTypeFallbackTest` — ветка по драйверу. Остальные 79 (`Arch`, `Http`, `Export`, `Operations`, `Unit`, `Providers`,
остальные `Document/`, `Comment/` …) — не `database`.

## azgard, filament, load

- azgard: `RealAzgard/`, `RealAzgardIsolated/` — реальная миграция хранилища AzGuard и grants на БД → `database`; адаптеры/провайдеры/резолвер — нет.
- filament: ни одного `database`: ресурсы панели идут через Eloquent и Livewire, SQL принадлежит core.
- load: все 19 файлов — `stand` (см. ниже).

## `serial`

fork-гонки (`pcntl_fork`, `ForkRace`) и всё, что трогает глобальное состояние процесса (`sys_get_temp_dir()` glob). Параллельный запуск падает или даёт ложный
зелёный: каждый воркер — своя БД, а гонке нужна общая. Состав: core 9 файлов (Concurrency 5, `VaulterDatabaseTest`, `ShareSecurityBoundaryTest`,
`TenantProvisioningRaceTest`, `ConcurrentDeliveryTest`), media 1, drive 16 + 2 теста-уровневых, documents 7. Все `serial` — также `database` (проверяет `TestGroupsTest`).
Список «падает в параллели» из P1.3: отдельной находки там нет (P1.3 отметил только skeleton-writers, они остались отдельными партициями) — серийными сделаны
fork-файлы и тест-уровневые `serial`.

## `engine:*` — пропуск до сборки схемы

Группы `engine:server` (любой серверный движок) и `engine:pgsql`/`engine:mysql` читает `SuiteDatabaseConnection::skipOnForeignEngine()`, вызываемый первым
в `defineDatabaseMigrations()` каждого TestCase набора (приложение уже поднято — хуки теста и фасады работают, схемы ещё нет). Поставлены только файлам, где
**все** тесты пропускаются на SQLite (проверено числом skipped до/после): core `Concurrency/`, `TenantProvisioningRaceTest`; media `ScopeKeyReconcileTest`
(server), `SweepPendingSerializableTest` (pgsql); drive 15 fork-файлов; documents `Concurrency/`. Эффект на SQLite (один процесс, машина под нагрузкой load average ≈10, замер по одному разу): core
Concurrency+TenantProvisioningRace, 16 skipped — 3,8 с → 0,9 с; drive `Concurrency/` serial-проход — 38 skipped + 1 passed за ≈3 с (до: весь каталог с 4 проходящими тестами — 9,6 с,
наборы не равны); числа skipped/passed те же (core 16, drive 38+1(+3 CompleteUploadRetry, теперь в параллельной линии), documents 27, media 2 и 1). Пропуск из `setUp()` до
`parent::setUp()` сломан (фасады в `afterEach` тестов) — не использовать. `HostKeyMysqlTestCase` уже пуст в `defineDatabaseMigrations()`, схема там не строится.

## `extended`

Пусто. Кандидат в масштаб один: `WideNodeListingTest` (core `Access/`, `IdStrategy/Ulid/`) — 65 540 строк выше лимита биндов PG. Дешёвого эквивалента «того же дефекта»
на PR нет: `AccessAwareListingTest` №12 проверяет чанкование на 30 потомках и не ловит переполнение биндов (константа `WHERE_IN_BIND_LIMIT` не настраивается).
По правилу пункта тест остаётся в `database` на PR; предложение для P1.7 — параметризуемый лимит биндов + дешёвый вариант (B−1/B/B+1). Остальные «масштабные» кейсы
(`PruneChangesCommandTest` — батч 1000+5 строк, `NodeKeyNarrowingTest` — 901 ключ, `ChangesV1Test` — 210) дёшевы и сами являются PR-эквивалентами. Полный диапазон seed
(`VAULTER_FULL_SEEDS`) в PHP-тестах пока не используется — сужение датасетов — решение владельца P1.7. Механизм (`extended` → `nofast`, полный прогон включает) готов и
покрыт `TestGroupsTest`.

## `stand`

`scripts/t stand` = все `stand`-тесты (весь `load/tests`, 19 файлов; SQLite, один процесс, без `nofast`/`nofull`) + реальные процессы из ключа `stand` в
`tests/suites.json` (`scripts/collab-e2e.sh`: `testbench serve` + хаб Hocuspocus). `all`, `all --full`, быстрая линия и `--changed` его не включают; k6 и
benchmark-сценарии не тесты runner'а и не запускаются ни одной его командой (`TestGroupsTest` проверяет, что плана stand без `k6`/`benchmark`). Безопасность не
переносилась в `stand`: контракты читаемости/трейсов покрыты тестами core/media/drive на PR.

## Database-free: схема не строится

Файл меняет базу на `TestCase + WithoutDatabaseSchema` (трейт переопределяет `defineDatabaseMigrations()`; не конфликтует с TestCase пути, `RefreshesDatabaseForSchemaVariant`
сохранён) **только** после доказательства: прогон файла без схемы зелёный (тест, дошедший до БД, упал бы). Доказано и переведено: core — `Http/Problems/` (6),
`Observability/Binding/MetricsRecorderBindingTest`, `Observability/Tracing/TraceRecorderBindingTest`, `Arch/CommandNamingTest`, `Unit/NodeNameTest`; media — `Configuration/`,
`Console/`; drive — `Arch/`, `Configuration/`, `Console/`, `Providers/`, `Http/DriveProblemCodesTest`; documents — `Arch/`, `Configuration/`, `Providers/`. Не переведены: `Observability/Tracing/
WebhookPipelineTracingTest` (падает без схемы — ходит в БД), `Operations/ProblemMappingTest` (красный и до пункта, см. ниже), `Schema/NodeSequenceSchemaTest`/`ScanEvidenceSchemaTest`/
`TenantIdentifierPredicateTest` (сами строят схему), azgard/filament (свои TestCase и Livewire/Filament-загрузка — отдельный замер не делался). В `tests/suites.json` такие файлы — партиции
`TestCase+WithoutDatabaseSchema` (`database: false`): на серверном движке runner пропускает их с причиной «database-free partition».

## Найдено по пути (не из этого пункта)

- `Http/Problems/ErrorsReferenceTest` падал в параллели: хелпер `fullProblemCatalog()` жил в `ProblemCatalogLangTest`, воркер без соседнего файла не находил его (`-j4` на каталоге
  красный и на базе). Хелпер вынесен в `ProblemCatalogHelpers.php`, оба файла делают `require_once`; имена и утверждения тестов не менялись.
- `Operations/ProblemMappingTest` красный на базе (`Call to undefined method …Exception::render()`: исключения больше не имеют `render()`, тест ждёт его) — регрессия вне P1.5, не
  правилась; нужно решение владельца пункта (тест или код).
- `packages/drive/tests/Fixtures` указан в `tests/zones.json`, но в git отсутствует (пустой каталог) → `ZonesMapTest` красный на свежем клоне; локально каталог воссоздан.

## Что проверено

`scripts/t baseline --compare` — имена и число тестов совпадают с immutable original; `tests/Repo/TestGroupsTest.php` (11) + `ZonesMapTest` + `TestRunnerTest` — зелёные; затронутые файлы
core/media/drive/documents/azgard/filament — зелёные на SQLite; V3–V5 — см. отчёт пункта.

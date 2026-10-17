P4.5 | packages/core/src/Testing/Support/SuiteDatabaseConnection.php:50 | докблок engine() оказался над migrationPaths(); вернуть его к engine()
P4.5 | tests/baseline/vue.list | `scripts/t baseline --compare` предупреждает: vue.list устарел относительно discovery (pre-existing, вне P4.5)
P4.5 | packages/core/src/Console/ModuleUninstallCommand.php:77 | текст отказа --force/--purge "not available yet (P4)" заменит P4.17
P4.5 | packages/core/src/Webhooks/Schema/WebhooksSchema.php:84 | FK webhook_endpoints.drive_id→drives в core без drive падает на pgsql/mysql; уйдёт с переездом в P4.13 (RC1)
P4.5 | UPGRADE.md/CHANGELOG | строка: dev-БД с main до RC1 пересоздать (migrate:fresh) — имена миграций сменились (RC1)
P4.6 | docs/reference/config.md, docs/ru/reference/config.md | таблица владельцев `models.blob` … `core` и страницы storage-and-blobs/tenancy: ключ теперь `vaulter-media.models.blob`, `Tenancy::without` (P4.20)
P4.6 | packages/media/tests/TestCase.php:23 | комментарий «Core provides the Contracts\Cas seam, the Blob model + v_blobs schema» устарел (контракты, модель и схема — media)
P4.6 | packages/drive/src/DriveModule.php:86 | docblock «two doctor checks» неверен (теперь 10 проверок, включая media.disk.probe/media.dedup.scope по D33)
P4.6 | packages/media/src/Models/Cas/Blob.php:3 | docblock ссылается на v_file_revision_blobs/FileRevision — уйдёт с pins (P4.7)
RC1-fix | docs/reference/compatibility.md:236 (+ru) | уточнить абзац про enforceMorphMap: алиасы теперь в morphMap(merge)
P4.7 | packages/media/src/Console/ReconcileBlobsCommand.php:51 | описание команды «from edge counts» → «from the pin counts» (идентичность cli description + docs/reference/commands.md, P4.20)
P4.7 | packages/media/tests/Cas/{BlobReconcilerTest.php:21,BlobGarbageCollectorTest.php:41,BlobGcPipelineTest.php:86,BlobDedupScopeBehaviourTest.php:85} | имена тестов говорят «ребро»/«drive_id» — теперь pins/storage_id (переименование = запись baseline CHANGES)
P4.7 | packages/media/tests/Cas/BlobGcPipelineTest.php:13-39 | комментарии «commit → attach → detach», «число рёбер» — теперь pin/unpin
P4.7 | docs/ (storage-and-blobs, gc, reference/schema, en+ru) | v_blob_references, BlobPins, storage_id, blob_unavailable, RevisionBlobLinks, doctor media.blobs.pins (P4.20 по ledger D23)
P4.7 | load/app/Console/SeedLoadDatasetCommand.php:~475-545 | сидер устарел до P4.7 (file_version_id/version_no, commit без scope); холодные рёбра вставляются без pins — GC их соберёт
P4.7 | packages/drive/src/Actions/Files/IngestFileAction.php:51 | docblock «→ version↔blob edge» — теперь edge + pin (RevisionBlobLinks)
P4.7 | packages/drive/src/Content/RevisionBlobLinks.php | RC2#3: латентный deadlock restore vs GC acquireMany при >1 blob на ревизию; добавить BlobPins::pinMany/assert 1 blob при манифестах
P4.7 | packages/media/src/Cas/BlobGarbageCollector.php:179 | RC2#2: устаревший комментарий '(possibly partial) object'; StorageDriver::put — зафиксировать атомарность в контракте
P4.8 | load/app/Console/SeedLoadDatasetCommand.php:20 | load/app (3 files) use nonexistent Vaulter\Enums\Access\GrantLevel (bit-level API gone before P4.8); load not run in PLAN3
P4.8 | packages/azgard/tests/ArchTest.php | заголовки arch-тестов ('only core') устарели: azgard зависит от drive (Access перенесён); require drive в composer — P4.14
P4.9 | scripts/inventory/move-types.php | инструмент не переносит записи tests/groups.php, uses()->in() в tests/Pest.php и не чистит stale-правила target-map/rules/*.json и src-путь пустой зоны в tests/zones.json — правятся руками
P4.10 | load/tests/Feature/R03SeedFileVersionsTest.php:10 | referred the long-gone FileVersion model (stand test, not run); switched to FileRevision, behaviour unverified
P4.10 | packages/core/tests/{PublicApi/OptionsTest,PublicApi/SequenceOptionContractTest,PublicApi/UploadSourceTest,Providers/AccessRegistrarTest,Providers/DriveSliceRegistrarTest,Unit/AccessEnumsTest,Unit/NodeNameTest,Support/DriveNameRulesConfigSeamTest,Support/SnapshotResourceTimestampTest,Operations/OperationTaxonomyTest}.php | drive-only tests outside the listed directories still live in core (they pass through core require-dev drive); move them in P4.16 together with the core cleanup
P4.10 | packages/core/tests/Arch/ArchTest.php:547 | arch tests about drive types (handlers, snapshots) still live in the core ArchTest and the cases carry "core" in their titles; ownership of the title/case moves with P4.16
P4.10 | packages/core/tests/Arch/OperationsBoundaryTest.php:13 | the case "core activity Operations depend on the drive package only through the moved access types" now checks only that ActivityOperations avoids Actions; title kept for the baseline ledger
P4.10 | tests/Repo/Arch/Fixtures/target-map/rules/core-drive.json:1 | "scope" prefixes still list the moved namespaces (harmless, the rules are gone)
P4.10 | packages/vue/src/naming.ts:2 | comment names the old FQCNs Vaulter\Naming\PortableNamePolicy / Vaulter\Support\NodeName
P4.10 | packages/drive/tests/Console/MakeFileHandlerCommandTest.php | binds under Console (TestCase + WithoutDatabaseSchema, skeleton partition) while in core it ran with RefreshDatabase; it never touched the database
P4.11 | packages/media/src/MediaModule.php:70 | the ContentScanner capability reads the drive-owned key vaulter-drive.scan.scanner (a string cross-reference, no class dependency; media stays below drive); consider a media-owned scanner key in the P4.14 require pass
P4.11 | packages/media/composer.json:40 | the temporary autoload-dev Vaulter\Drive\ (../drive) from P4.5 stays: the media TestCase still boots the drive module for the CAS tests; remove with the explicit-require pass (P4.14)
P4.11 | packages/core/src/Configuration/ConfigValidator.php:75 | validateDrive and the VaulterConfig drive facades (access/scan/sharing/listing/downloads/changes, model, featureEnabled) still sit in core and delegate to DriveConfig; they leave with the core cleanup (P4.16, I1/I2 blocks)
P4.11 | packages/core/src/Doctor/Checks/RoutesAuthCheck.php:1 | kernel check registered by DriveModule under core.routes.auth (it inspects the drive route stack); revisit ownership in P4.16
P4.11 | packages/drive/src/Http/Support/HttpOperationContextFactory.php:1 | core/src/Webhooks/WebhookEndpointWrites still imports it from drive (webhooks move to their package in P4.13, which depends on drive)
P4.11 | packages/core/tests/Fixtures/golden/routes-v1.json:789 | the golden changed only in the FQCN of the ResolveShareToken middleware (moved to Vaulter\Drive\Http\Middleware); route names, paths, methods and order are unchanged
P4.11 | packages/drive/tests/Feature/HandlerBaselineTest.php:19 | comments still say "config/vaulter.php" for the pre-P0.4 published config (historical text)
P4.12 | packages/core/tests/Feature/VaulterManagerExporterHandlerSeamTest.php:1 | file/class name and `$manager` variables still say VaulterManager (the class is Vaulter\Drive\Vaulter); the test and ServiceProviderTest still live in core, move to drive with the other entry-point tests in P4.16
P4.12 | packages/documents/src/Collaboration/CollaborationDriverManager.php:18 | docblock mentions a nonexistent Vaulter::driver() (was VaulterManager::driver())
P4.12 | packages/core/src/Testing/InteractsWithVaulter.php:23 | core test trait imports the drive facade (i1/i2 allowlist entries retargeted, removed_in P4); moves to drive in P4.16
P4.12 | packages/core/src/Configuration/VaulterConfig.php:85 | RC3#2: featureEnabled()/model() делегируют в DriveConfig — падает без drive; убрать делегаты в P4.16
P4.13 | packages/core/src/Configuration/ConfigValidator.php:45 | removed-key guard `vaulter.features.webhooks` still names the webhooks module in core source (ActivationTest pins it); drop it with the 0.x legacy in the P4.16 cleanup
P4.13 | load/app/Console/{QueueProbeCommand,ChaosOperationCommand}.php:15 | import pre-P3 classes that no longer exist (Vaulter\Webhooks\OutboxRelay, OutboxRecorder, Vaulter\Models\Webhooks\Outbox): the load queue probes are broken since P3, not by P4.13
P4.13 | packages/webhooks/src/Configuration/WebhooksConfig.php:50 | REMOVED_KEYS guards pre-1.0 keys under the new vaulter-webhooks root (D21: no legacy); delete with its ConfigTest cases before the P7 freeze
P4.13 | packages/core/tests/Modules/*Test.php | the core activation/ownership/route-cache suites use the real webhooks module as their opt-in example (core requires the package as a dev dependency); a fixture module would cut the dev dependency
P4.13 | packages/webhooks/tests/Webhooks/WebhookTestHelpers usages in packages/core/tests | core outbox tests (OutboxRelay/Write, EventRecorder, Concurrency/Outbox*) use the webhooks.fanout consumer as a durable consumer; replace with a core fixture consumer (ScriptedConsumer) and drop `EnablesWebhooksModule` from core
P4.15 | packages/drive/src/Queries/VisibleDrivesQuery.php:62 | drives() decides View on each root through the drive's own handle (about 10 queries per listed drive, linear, not per row of anything else); one batched root evaluation across drive contexts (OperationGuard::abilitiesMany takes a single drive) would make it constant
P4.15 | packages/filament/src/Support/PanelGrants.php:35 | grants and share links are listed per manageable node (one access/share query per node, bounded by PanelNodes::LIMIT); a drive-level `grantsMany`/`sharesMany` batch on the handle would remove the per-node call
P4.15 | packages/filament/src/Resources/DriveResource.php:62 | the disk badge column is replaced by the profile badge: DriveSnapshot carries no disk (storage_type was already gone from the model); add `disk` to the snapshot only if the panel needs it
P4.15 | docs/ + docs/ru/ (filament pages), packages/filament/README.md | still describe model-swap governance of the resources, the Drive and Activity view pages and Eloquent-scoped listings; rewrite in the P4.20 pass (rows are drive snapshots, details open as a table modal, node/grant/share/activity need the drive's root View)
P4.15 | tests/baseline/vue.list | stale against discovery (pre-existing, vue untouched by P4.15): `scripts/t baseline --write -p vue`
P4.16 | packages/core/src/Configuration/ConfigNormalizer.php:27 | the kernel still lists the class-list keys of vaulter-drive, vaulter-documents and vaulter-azgard as strings (not flagged by scan-i1, which reads FQCNs, tables and routes): each module should contribute its own normalised keys
P4.16 | packages/core/src/Http/Middleware/ApplyResponseCachePolicy.php:30 | PUBLIC_SHARE_ROUTES names the drive route family `vaulter.v1.public-shares.` inside the kernel; move to a route-label style contribution
P4.16 | packages/core/src/Http/Support/RepresentationTag.php:30 | node()/document() builders name drive and documents resources in the kernel (strings only); move to the owners
P4.16 | packages/core/src/ValueObjects/Operations/ScopeRef.php:19 | ScopeRef::DRIVE, EventScope::$driveId/$profile and OutboxWriter scope_kind 'drive' keep the drive vocabulary in the neutral values of the kernel; rename to a neutral scope kind in the P7 freeze pass
P4.16 | packages/core/src/Observability/TracingSpanNames.php:15 | UPLOAD_SWEEP and WEBHOOK_* span names belong to the drive and webhooks packages
P4.16 | packages/core/src/Testing/Tenancy/MutableTenantResolver.php:27 | the kit lists the entity keys of drive tables (drives, nodes, blobs, ...); read them from ModuleSchemas instead
P4.16 | packages/core/src/Http/Problems/ProblemRenderer.php:160 | the 404 fallback code `node_not_found` and RoutesConfig::uploadsRateLimit / config routes.rate_limits.uploads are drive vocabulary in the kernel
P4.16 | packages/core/src/Exceptions/Nodes/SequenceOverflowException.php:5 | node_sequence_exhausted exception still lives in the kernel namespace Vaulter\Exceptions\Nodes
P4.16 | packages/core/tests/Testing/InteractsWithVaulterTest.php:1 | still in the core suite: moving it needs a drive partition `InteractsWithVaulter+RefreshDatabase+TestCase` in tests/suites.json (the trait itself moved to Vaulter\Drive\Testing)
P4.16 | packages/core/tests/Arch/OperationsBoundaryTest.php:1 | two drive-only arch rules in the core suite, now covered by the drive OperationsBoundaryTest (all controllers); delete with a ledger entry
P4.16 | packages/core/tests | 107 of 230 core test files name Vaulter\Drive\ / Documents / Media / Webhooks types (core is a full-stack suite); the tests of drive concepts should move to the owners in a separate pass (move-types --tests handles only whole directories)
P4.16 | packages/drive/tests/Arch/OperationsBoundaryTest.php:1 | the I9 model ratchet lists nine controllers that still name a model (protocol controllers, ListPublicShareNodes, six webhook admin controllers) and the webhook admin still calls Actions/Repositories directly (no Operations for the global webhook surface)
P4.16 | tests/baseline/vue.list:1 | stale against discovery (pre-existing): scripts/t baseline --write -p vue
P4.17 | docs/reference/commands.md:90, docs/ru/reference/commands.md:91 | «--force/--purge недоступны (P4)» — обновить в P4.20
P4.17 | media/ModuleUninstall | media --purge оставляет объекты хранилища сиротами (до GC)
P4.17 | core/ModuleUninstallCommand | purge без guard выключенного модуля/lock — возможны записи при живом трафике
P4.17 | core/ModuleUninstallCommand | MySQL: DDL коммитится, purge+rollback не атомарны; fresh-app тесты на серверных БД пересмотреть в PLAN4
P4.18 | core/tests/Modules ModuleUninstallTest, CleanInstallMatrixTest | общий хелпер подмены container/facade root вместо двух копий …In()
P4.18 | core/tests/Modules/Fixtures/Apps/AppSet.php | docblock providers: string вместо class-string (azgard/filament статически неизвестны)
P4.18 | CleanInstallMatrixTest | на pgsql/mysql wipe _test БД внутри DatabaseMigrations — проверить в PLAN4 (не запускалось)
RC4 | core/ModuleUninstallCommand | TOCTOU сужен, не закрыт; для строгости на pgsql — LOCK TABLE внутри транзакции (PLAN4)
RC4 | core/KeyColumnTypesTest | ожидание 39 колонок не проверялось на pgsql/mysql (на sqlite пропуск)
RC4 | exit-код 3 (PARTIAL) | не в Cli::EXIT_CONSTANTS/ledger exit_codes — только запись в changes.json
P4.19 | scripts/release/consumer-check.sh core | реальный прогон не выполнялся (PLAN4): core-тестам нужны корневые файлы (openapi/) и root-only dev-зависимости (open-telemetry, flysystem-aws-s3-v3), не объявленные в core/composer.json
P4.19 | consumer-check.sh, ConsumerCheckTest | ~2–3 с на запуск скрипта из-за process spawn
P4.19 | monorepo-split.yml | владельцу создать репозиторий axioma-studio/vaulter-webhooks (prerequisite)
P4.19 | docs/, README | заметка про webhooks split-target и job consumer-isolation — в P4.20

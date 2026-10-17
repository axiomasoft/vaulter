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

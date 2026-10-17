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

<?php

declare(strict_types=1);

namespace Vaulter\Load\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Vaulter\Drive\Content\RevisionBlobLinks;
use Vaulter\Drive\Enums\Access\Effect;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Media\Contracts\Cas\BlobStore as BlobStoreContract;
use Vaulter\Support\Config;
use Vaulter\Testing\Fixtures\TestUser;
use Vaulter\Webhooks\Models\WebhookEndpoint;

/**
 * Seed the stand to its tier's volume envelope,
 * "dirty" state included (D-LOAD-3): expired upload sessions, orphan blobs, an
 * outbox/webhook-retry backlog, a many-grant actor. Idempotent by `dataset_id` (a
 * hash of tier + profile + this file's SEED_VERSION) unless `--fresh`.
 *
 * Everything mutating rows at tier scale goes through chunked query-builder batch
 * inserts / set-based SQL (`insertBatchReturningIds()`, the two `reconcileFile*()`
 * statements) — never one Eloquent model per row: a
 * million-row v_activity insert through the ORM does not finish in this decade.
 * `BlobStore::commit()` (real CAS engine, one call per HOT-SET object only — see
 * `seedBlobsAndVersions()`) and `vaulter:blobs:reconcile` (existing package command)
 * are the two deliberate exceptions, both bounded by the hot-set/blob-count profile
 * knob, not by total row volume.
 *
 * Assumes `vaulter.ids.strategy = bigint` (LoadServiceProvider) — every id handled
 * here is a plain auto-increment integer; a ulid/uuid7 stand cannot use this command
 * as written (guarded in handle()).
 */
final class SeedLoadDatasetCommand extends Command
{
    protected $signature = 'load:seed
        {--fresh : Truncate every v_* table and reseed even if a matching dataset already exists}
        {--tier= : Volume profile S0|S|M — defaults to $TIER}';

    protected $description = "Seed the VLT-LOAD stand to a tier's volume+dirty-state envelope (reference-env.md §2).";

    private const int INSERT_CHUNK = 2_000;

    private const int SEED_OWNER_ACTOR_ID = 1; // matches README's `X-Load-Actor: 1` smoke curl.

    private const int MANY_GRANT_ACTOR_ID = 900_001;

    private const int NORMAL_ACTOR_ID = 900_002;

    private const string SEED_VERSION = '2026-09-28.1';

    private const array WEBHOOK_EVENT_POOL = ['node.created', 'file.uploaded', 'version.created', 'node.trashed', 'grant.created'];

    public function handle(BlobStoreContract $blobStore): int
    {
        if (Config::idStrategy() !== 'bigint') {
            $this->components->error('load:seed assumes vaulter.ids.strategy=bigint (LoadServiceProvider) — got ['.Config::idStrategy().'].');

            return self::FAILURE;
        }

        $tier = strtoupper((string) ($this->option('tier') ?: env('TIER', '')));

        if ($tier === '') {
            $this->components->error('Pass --tier=S0|S|M or set $TIER.');

            return self::FAILURE;
        }

        $profile = $this->loadProfile($tier);

        if ($profile === null) {
            return self::FAILURE;
        }

        $datasetId = $this->datasetId($tier, $profile);
        $manifestPath = $this->manifestPath($datasetId);

        if (!$this->option('fresh') && is_file($manifestPath)) {
            $this->components->info("Dataset {$datasetId} already seeded (manifest exists) — pass --fresh to reseed.");
            $this->line($datasetId);

            return self::SUCCESS;
        }

        $start = microtime(true);

        $this->truncateAll();

        [$storageId, $rootId] = $this->seedStorageRoot();

        $tree = $this->seedTree(storageId: $storageId, rootId: $rootId, profile: $profile['nodes']);
        $blobStats = $this->seedBlobsAndVersions(blobStore: $blobStore, fileNodeIds: $tree['fileNodeIds'], datasetId: $datasetId, profile: $profile);
        $this->reconcileFileAggregates();

        $manyGrantAnchors = $this->seedGrants(rootId: $rootId, folderNodeIds: $tree['folderNodeIds'], profile: $profile['node_grants']);
        $shareCount = $this->seedShares(nodeIds: [...$tree['folderNodeIds'], ...$tree['fileNodeIds']], profile: $profile['node_shares']);
        $commentCount = $this->seedComments(nodeIds: [...$tree['folderNodeIds'], ...$tree['fileNodeIds']], profile: $profile['node_comments']);
        $docVersionStats = $this->seedDocumentVersions(storageId: $storageId, rootId: $rootId, profile: $profile['node_document_versions']);

        [$sessionCounts, $partCount] = $this->seedUploadSessions(storageId: $storageId, folderNodeIds: $tree['folderNodeIds'], profile: $profile['upload_sessions']);
        $endpointIds = $this->seedWebhookEndpoints(profile: $profile['webhook_endpoints']);
        $this->seedOutbox(profile: $profile['outbox']);
        $this->seedWebhookDeliveries(endpointIds: $endpointIds, profile: $profile['webhook_deliveries']);
        $this->seedActivity(storageId: $storageId, nodeIds: [...$tree['folderNodeIds'], ...$tree['fileNodeIds']], profile: $profile['activity']);

        // Rebuilds ref_count from the edges we just created (BlobReconciler::reconcile()
        // — a plain rebuild never fails; dangling rows are reported, not fatal). Cold
        // committed/orphan-gc blobs are EXPECTED to surface as dangling (their
        // bytes are synthetic-only), not a defect this run introduced.
        $this->call('vaulter:blobs:reconcile');

        $elapsed = microtime(true) - $start;

        $manifest = [
            'dataset_id' => $datasetId,
            'tier' => $tier,
            'seed_version' => self::SEED_VERSION,
            'seeded_at' => Carbon::now()->toIso8601String(),
            'elapsed_seconds' => round($elapsed, 1),
            'storage_id' => $storageId,
            'root_node_id' => $rootId,
            'actors' => [
                'seed_owner' => self::SEED_OWNER_ACTOR_ID,
                'many_grant' => self::MANY_GRANT_ACTOR_ID,
                'normal' => self::NORMAL_ACTOR_ID,
            ],
            'counts' => [
                'nodes' => count($tree['folderNodeIds']) + count($tree['fileNodeIds']),
                'folder_nodes' => count($tree['folderNodeIds']),
                'file_nodes' => count($tree['fileNodeIds']),
                'backbone_leaf_node_id' => $tree['backboneLeafId'],
                'wide_node_id' => $tree['wideNodeId'],
                'blobs_total' => $blobStats['liveCount'] + $blobStats['orphanCount'],
                'blobs_live' => $blobStats['liveCount'],
                'blobs_orphan_gc_candidate' => $blobStats['orphanGcCandidateCount'],
                'blobs_orphan_sweep_candidate' => $blobStats['orphanSweepCandidateCount'],
                'blobs_hot_set' => $blobStats['hotCount'],
                'file_versions' => $blobStats['versionCount'],
                'node_grants_many_grant_anchors' => count($manyGrantAnchors),
                'node_shares' => $shareCount,
                'node_comments' => $commentCount,
                'node_document_versions' => $docVersionStats['versionCount'],
                'document_nodes' => $docVersionStats['nodeCount'],
                'upload_sessions_expired' => $sessionCounts['expired'],
                'upload_sessions_healthy' => $sessionCounts['healthy'],
                'upload_parts' => $partCount,
                'webhook_endpoints' => count($endpointIds),
                'webhook_deliveries_due' => $profile['webhook_deliveries']['due'],
                'outbox_backlog' => $profile['outbox']['backlog'],
                'activity' => $profile['activity']['total'],
            ],
            'ids' => [
                'many_grant_anchor_node_ids' => $manyGrantAnchors,
            ],
        ];

        @mkdir(dirname($manifestPath), recursive: true);
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->components->info("Seeded dataset {$datasetId} (tier {$tier}) in ".round($elapsed, 1).'s.');
        $this->line($datasetId);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadProfile(string $tier): ?array
    {
        $path = self::loadRoot()."/seed/profiles/{$tier}.php";

        if (!is_file($path)) {
            $this->components->error("Unknown tier [{$tier}] — expected one of S0|S|M (L is spec-only, see load/seed/profiles/L.php).");

            return null;
        }

        /** @var array<string, mixed> $profile */
        $profile = require $path;

        if (($profile['run'] ?? true) === false) {
            $this->components->error("Tier [{$tier}] is spec-only (reference-env.md §3/D25) — load:seed refuses to run it.");

            return null;
        }

        return $profile;
    }

    private function datasetId(string $tier, array $profile): string
    {
        return 'vlt-load-'.substr(hash('sha256', $tier.self::SEED_VERSION.json_encode($profile)), 0, 16);
    }

    private function manifestPath(string $datasetId): string
    {
        return self::loadRoot()."/seed/out/{$datasetId}.json";
    }

    /**
     * `base_path()` resolves to Testbench's OWN internal skeleton
     * (`vendor/orchestra/testbench-core/laravel`), not this repo — verified live
     * against the SUT (`vendor/bin/testbench tinker --execute='echo
     * base_path();'`). `load/seed/` is a repo-relative path this file's own location
     * anchors reliably: Console -> app -> load.
     */
    private static function loadRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Truncate every v_* table this stand owns, in one statement (Postgres-only
     * stand — CASCADE resolves FK order; RESTART IDENTITY so a reseed's ids start
     * clean and old manifests never alias new rows).
     */
    private function truncateAll(): void
    {
        $tables = [
            'webhook_deliveries', 'webhook_endpoints', 'outbox',
            'activity', 'upload_parts', 'upload_sessions',
            'node_document_versions', 'node_comments', 'node_shares', 'node_grants',
            'file_version_blobs', 'file_versions', 'files', 'blobs',
            'nodes', 'storages',
        ];

        $quoted = implode(', ', array_map(static fn (string $t): string => Config::table($t), $tables));

        $this->connection()->statement("TRUNCATE TABLE {$quoted} RESTART IDENTITY CASCADE");

        // TRUNCATE only clears Postgres — verified live: a --fresh reseed without
        // this left the PREVIOUS run's hot-set bytes in MinIO (random content per
        // run, so no key collision, just silent accumulation of orphaned objects a
        // real orphan-count scope check would then over-count).
        // deleteDirectory('') is a documented no-op on Flysystem's S3 adapter for an
        // empty/root path (verified live: returns false, deletes nothing) — delete
        // the listed files explicitly instead.
        $disk = Storage::disk(Config::defaultDisk());
        $disk->delete($disk->allFiles());
    }

    /**
     * @return array{0: int, 1: int} [storageId, rootNodeId]
     */
    private function seedStorageRoot(): array
    {
        $host = DriveTestHelpers::host(self::SEED_OWNER_ACTOR_ID);
        $drive = Vaulter::for($host);
        $storage = $drive->model();
        $root = $drive->root();

        AccessTestHelpers::grant(
            node: $root,
            level: GrantLevel::Owner,
            permissions: GrantLevel::Owner->bits(),
            subjectId: self::SEED_OWNER_ACTOR_ID,
        );

        return [(int) $storage->getKey(), (int) $root->getKey()];
    }

    /**
     * Grows the tree to `profile.total` nodes: a typical randomly-branching
     * folder/file mix bounded by `typical_depth`/`typical_width`, PLUS two fixed
     * structural outliers reference-env.md §2.1 calls out explicitly — a singly-
     * chained 64-deep backbone (NodeReadRepository::DEFAULT_MAX_DEPTH, the CTE
     * cutoff boundary) and one wide node with `wide_node_children` direct children
     * (partial-UNIQUE(parent_id, lower(name)) + pagination stress). Both outliers
     * come out of the same total-node budget, not on top of it.
     *
     * @return array{folderNodeIds: list<int>, fileNodeIds: list<int>, backboneLeafId: int, wideNodeId: int}
     */
    private function seedTree(int $storageId, int $rootId, array $profile): array
    {
        $folderNodeIds = [];
        $fileNodeIds = [];
        $budget = $profile['total'];

        // The backbone: a singly-chained folder path, depth exactly backbone_depth.
        // Each row's parent is the PREVIOUS row's id, so this is a strict one-row-at-
        // a-time insert — cheap: backbone_depth is 64 on every tier, never a volume
        // knob, unlike everything else this method seeds in INSERT_CHUNK batches.
        $parent = $rootId;
        $backboneLeafId = $rootId;

        for ($depth = 1; $depth <= $profile['backbone_depth']; $depth++) {
            $row = $this->folderRow(storageId: $storageId, parentId: $parent, name: 'backbone-'.Str::padLeft((string) $depth, 4, '0'));
            $parent = $this->insertBatchReturningIds(Config::table('nodes'), [$row])[0];
            $backboneLeafId = $parent;
            $folderNodeIds[] = $parent;
            $budget--;
        }

        // The wide node: one folder directly under root, then wide_node_children
        // direct file children with sequential, guaranteed-unique names.
        $wideParentIds = $this->insertBatchReturningIds(Config::table('nodes'), [
            $this->folderRow(storageId: $storageId, parentId: $rootId, name: 'wide-node'),
        ]);
        $wideNodeId = $wideParentIds[0];
        $folderNodeIds[] = $wideNodeId;
        $budget--;

        foreach ($this->batchChunks($profile['wide_node_children']) as $chunk) {
            $rows = [];

            foreach (range(1, $chunk) as $i) {
                $rows[] = $this->fileRow(storageId: $storageId, parentId: $wideNodeId, name: 'wide-'.Str::padLeft((string) (count($fileNodeIds) + $i), 6, '0'));
            }

            $ids = $this->insertBatchReturningIds(Config::table('nodes'), $rows);
            array_push($fileNodeIds, ...$ids);
            $budget -= count($ids);
        }

        // The typical randomly-branching tree over the remaining budget. A
        // branching process with a <100% folder chance and a hard depth cap dies
        // out on its own — verified live on S0 (typical_depth=4, typical_width=8):
        // it saturated around ~350 nodes, nowhere near a 20 000 budget.
        // reference-env.md §2.1's depth/width numbers are the TYPICAL shape, not a
        // cap that may starve the total node count sitting next to them, so growth
        // runs in two phases: natural (frontier-driven, produces the depth/width
        // shape) until it dies out or the budget runs dry, THEN — if budget
        // remains — a bulk, INSERT_CHUNK-batched fill of plain files under random
        // EXISTING folders (no per-node round trip; a frontier-driven refill here
        // would cost one DB round trip per `typical_width`-sized batch, thousands
        // of them for a large overflow).
        $frontier = new \SplQueue;
        $frontier->enqueue([$rootId, 0]);
        $nameSeq = 0;

        while ($budget > 0 && !$frontier->isEmpty()) {
            [$parentId, $depth] = $frontier->dequeue();
            $width = min(random_int(1, max(1, $profile['typical_width'])), $budget);
            $rows = [];
            $childIsFolder = [];

            for ($i = 0; $i < $width; $i++) {
                $nameSeq++;
                $isFolder = $depth < $profile['typical_depth'] && random_int(1, 100) <= 60;
                $childIsFolder[] = $isFolder;

                $rows[] = $isFolder
                    ? $this->folderRow(storageId: $storageId, parentId: $parentId, name: 'dir-'.$nameSeq)
                    : $this->fileRow(storageId: $storageId, parentId: $parentId, name: 'file-'.$nameSeq.'.bin');
            }

            $ids = $this->insertBatchReturningIds(Config::table('nodes'), $rows);

            foreach ($ids as $idx => $id) {
                if ($childIsFolder[$idx]) {
                    $folderNodeIds[] = $id;
                    $frontier->enqueue([$id, $depth + 1]);
                } else {
                    $fileNodeIds[] = $id;
                }
            }

            $budget -= count($ids);
        }

        while ($budget > 0) {
            $chunk = min(self::INSERT_CHUNK, $budget);
            $rows = [];

            for ($i = 0; $i < $chunk; $i++) {
                $nameSeq++;
                $rows[] = $this->fileRow(storageId: $storageId, parentId: $folderNodeIds[array_rand($folderNodeIds)], name: 'overflow-'.$nameSeq.'.bin');
            }

            $ids = $this->insertBatchReturningIds(Config::table('nodes'), $rows);
            array_push($fileNodeIds, ...$ids);
            $budget -= count($ids);
        }

        return [
            'folderNodeIds' => $folderNodeIds,
            'fileNodeIds' => $fileNodeIds,
            'backboneLeafId' => $backboneLeafId,
            'wideNodeId' => $wideNodeId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function folderRow(int $storageId, int $parentId, string $name): array
    {
        // Same key SET as fileRow() (mime_type/extension included as null) on
        // purpose: insertBatchReturningIds() derives a chunk's column list from row
        // 0 only, and seedTree()'s typical-growth loop mixes folder/file rows in one
        // batch — a narrower folder row would silently misalign or drop columns.
        $createdAt = $this->randomPastTimestamp();

        return [
            'storage_id' => $storageId,
            'parent_id' => $parentId,
            'kind' => NodeKind::Folder->value,
            'name' => $name,
            'mime_type' => null,
            'extension' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fileRow(int $storageId, int $parentId, string $name): array
    {
        $createdAt = $this->randomPastTimestamp();

        return [
            'storage_id' => $storageId,
            'parent_id' => $parentId,
            'kind' => NodeKind::File->value,
            'name' => $name,
            'mime_type' => 'application/octet-stream',
            'extension' => pathinfo($name, PATHINFO_EXTENSION) ?: null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    /**
     * v_files + v_file_versions + v_blobs + v_file_version_blobs for every file
     * node. Only the first `hot_set` versions get real bytes (BlobStore::commit(),
     * the real CAS engine — one call per hot object, never per cold one); the rest
     * are metadata-only rows with a valid-shaped, deterministic sha256/storage_key
     * (Implementation Rules: hot-set hashes MUST be real, everything else may be
     * synthetic). Orphan blobs (no edge) are split between
     * the mark-phase scope (committed, ref_count reconciled to 0) and the
     * sweep-phase scope (tombstoned, delete_after_at in the past) — see L-03 /
     * BlobGarbageCollector::mark()/sweep(), both walk `candidates()` unbounded.
     *
     * @return array{liveCount: int, orphanGcCandidateCount: int, orphanSweepCandidateCount: int, orphanCount: int, hotCount: int, versionCount: int}
     */
    private function seedBlobsAndVersions(BlobStoreContract $blobStore, array $fileNodeIds, string $datasetId, array $profile): array
    {
        $fileTable = Config::table('files');
        $versionTable = Config::table('file_versions');
        $edgeTable = Config::table('file_version_blobs');
        $blobTable = Config::table('blobs');

        $fileIds = [];

        foreach ($this->arrayChunks($fileNodeIds, self::INSERT_CHUNK) as $chunk) {
            $rows = array_map(static fn (int $nodeId): array => ['node_id' => $nodeId], $chunk);
            array_push($fileIds, ...$this->insertBatchReturningIds($fileTable, $rows));
        }

        $extraShare = $profile['file_versions']['extra_version_share'];
        $hotBudget = $profile['blobs']['hot_set'];
        $avgHotBytes = $profile['blobs']['hot_set_avg_bytes'];
        $versionCount = 0;
        $hotCount = 0;
        $liveCount = 0;
        $edgeRows = [];

        foreach (array_chunk(array_combine($fileIds, array_slice($fileNodeIds, 0, count($fileIds))), 500, preserve_keys: true) as $chunk) {
            $blobRows = [];
            $versionRows = [];

            foreach ($chunk as $fileId => $nodeId) {
                $versionsForFile = random_int(1, 100) <= (int) round($extraShare * 100) ? 2 : 1;

                for ($v = 1; $v <= $versionsForFile; $v++) {
                    $versionCount++;
                    $isHot = $hotCount < $hotBudget;

                    if ($isHot) {
                        $hotCount++;
                        $liveCount++;
                        $bytes = random_bytes(max(16, (int) ($avgHotBytes * random_int(80, 120) / 100)));
                        $stream = fopen('php://temp', 'r+b');
                        fwrite($stream, $bytes);
                        rewind($stream);

                        $blob = $blobStore->commit($stream);
                        fclose($stream);

                        $versionId = $this->insertBatchReturningIds($versionTable, [$this->fileVersionRow(
                            fileId: $fileId,
                            versionNo: $v,
                            sha256: $blob->sha256,
                            sizeBytes: strlen($bytes),
                            name: 'v'.$v,
                            blobId: (int) $blob->getKey(),
                        )])[0];

                        $versionModel = Config::fileVersionModel();
                        app(RevisionBlobLinks::class)->attach(revision: $versionModel::query()->find($versionId), blob: $blob);

                        continue;
                    }

                    $liveCount++;
                    $sha256 = hash('sha256', "{$datasetId}-cold-{$fileId}-{$v}");
                    $sizeBytes = random_int(512, 65_536);

                    $blobRows[] = $this->coldBlobRow($sha256, $sizeBytes);
                    $versionRows[] = ['fileId' => $fileId, 'versionNo' => $v, 'sha256' => $sha256, 'sizeBytes' => $sizeBytes];
                }
            }

            if ($blobRows !== []) {
                $blobIds = $this->insertBatchReturningIds($blobTable, $blobRows);
                $newVersionRows = [];

                foreach ($versionRows as $i => $vr) {
                    $newVersionRows[] = $this->fileVersionRow(fileId: $vr['fileId'], versionNo: $vr['versionNo'], sha256: $vr['sha256'], sizeBytes: $vr['sizeBytes'], name: 'v'.$vr['versionNo'], blobId: $blobIds[$i]);
                }

                $versionIds = $this->insertBatchReturningIds($versionTable, $newVersionRows);

                foreach ($versionIds as $i => $versionId) {
                    $edgeRows[] = ['file_version_id' => $versionId, 'blob_id' => $blobIds[$i], 'role' => 'data', 'ordinal' => 0];
                }
            }

            if (count($edgeRows) >= self::INSERT_CHUNK) {
                foreach ($this->arrayChunks($edgeRows, self::INSERT_CHUNK) as $edgeChunk) {
                    $this->connection()->table($edgeTable)->insert($edgeChunk);
                }
                $edgeRows = [];
            }
        }

        if ($edgeRows !== []) {
            foreach ($this->arrayChunks($edgeRows, self::INSERT_CHUNK) as $edgeChunk) {
                $this->connection()->table($edgeTable)->insert($edgeChunk);
            }
        }

        $orphanTotal = $profile['blobs']['orphan'];
        $gcCandidates = intdiv($orphanTotal, 2);
        $sweepCandidates = $orphanTotal - $gcCandidates;

        $orphanRows = [];

        for ($i = 0; $i < $gcCandidates; $i++) {
            $orphanRows[] = $this->coldBlobRow(hash('sha256', "{$datasetId}-orphan-gc-{$i}"), random_int(512, 65_536), status: 'committed');
        }

        for ($i = 0; $i < $sweepCandidates; $i++) {
            $orphanRows[] = $this->coldBlobRow(
                hash('sha256', "{$datasetId}-orphan-sweep-{$i}"),
                random_int(512, 65_536),
                status: 'tombstoned',
                deleteAfterAt: Carbon::now()->subDays(random_int(1, 30)),
            );
        }

        foreach ($this->arrayChunks($orphanRows, self::INSERT_CHUNK) as $chunk) {
            $this->connection()->table($blobTable)->insert($chunk);
        }

        return [
            'liveCount' => $liveCount,
            'orphanGcCandidateCount' => $gcCandidates,
            'orphanSweepCandidateCount' => $sweepCandidates,
            'orphanCount' => $orphanTotal,
            'hotCount' => $hotCount,
            'versionCount' => $versionCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fileVersionRow(int $fileId, int $versionNo, string $sha256, int $sizeBytes, string $name, int $blobId): array
    {
        $createdAt = $this->randomPastTimestamp();

        return [
            'file_id' => $fileId,
            'blob_id' => $blobId,
            'version_no' => $versionNo,
            'content_sha256' => $sha256,
            'size_bytes' => $sizeBytes,
            'original_name' => $name,
            'status' => 'committed',
            'created_at' => $createdAt,
            'committed_at' => $createdAt,
        ];
    }

    /**
     * A metadata-only blob row (no real bytes on disk). storage_key matches
     * the real Sha256ContentStore::keyFor() shape so it round-trips a CAS-shape scan
     * even though no object exists at that key.
     *
     * @return array<string, mixed>
     */
    private function coldBlobRow(string $sha256, int $sizeBytes, string $status = 'committed', ?Carbon $deleteAfterAt = null): array
    {
        $scope = $this->dedupScopeSegment();
        $storageKey = "{$scope}/".substr($sha256, 0, 2).'/'.substr($sha256, 2, 2)."/{$sha256}";

        return [
            'sha256' => $sha256,
            'size_bytes' => $sizeBytes,
            // The DB DEFAULT for this column is the literal string 'default' (schema
            // default, PanelTablesBuilder::defineBlobs) — NOT a configured Flysystem
            // disk name. Omitting this column made vaulter:blobs:reconcile crash on
            // its dangling-object check (Storage::disk('default') has no driver) the
            // first time it walked a cold row — set it explicitly to the real disk.
            'disk' => Config::defaultDisk(),
            'storage_key' => $storageKey,
            'status' => $status,
            'first_seen_at' => $this->randomPastTimestamp(),
            'committed_at' => $status === 'committed' || $status === 'tombstoned' ? $this->randomPastTimestamp() : null,
            'delete_after_at' => $deleteAfterAt,
        ];
    }

    private function dedupScopeSegment(): string
    {
        return match (Config::dedupScope()) {
            'global' => 'global',
            'storage' => 'snull',
            default => 'tnull',
        };
    }

    /**
     * Set-based reconciliation (two statements, not a PHP loop): each v_files row
     * points at its own latest version (by version_no), and each v_nodes row mirrors
     * its file's current_version_id/size_bytes. Bulk UPDATE...FROM, not per-row
     * Eloquent — the same discipline as every other seed step at this scale.
     */
    private function reconcileFileAggregates(): void
    {
        $filesTable = Config::table('files');
        $versionsTable = Config::table('file_versions');
        $nodesTable = Config::table('nodes');

        $this->connection()->statement(<<<SQL
            UPDATE {$filesTable} f
            SET current_version_id = latest.id,
                size_bytes = latest.size_bytes,
                version_count = latest.cnt
            FROM (
                SELECT DISTINCT ON (file_id) file_id, id, size_bytes,
                       COUNT(*) OVER (PARTITION BY file_id) AS cnt
                FROM {$versionsTable}
                ORDER BY file_id, version_no DESC
            ) latest
            WHERE f.id = latest.file_id
        SQL);

        $this->connection()->statement(<<<SQL
            UPDATE {$nodesTable} n
            SET current_version_id = f.current_version_id,
                size_bytes = f.size_bytes,
                file_id = f.id
            FROM {$filesTable} f
            WHERE f.node_id = n.id
        SQL);
    }

    /**
     * The many-grant actor: `many_grant_anchors` distinct SUBTREE-scoped Reader
     * grants on random folders (NodeReadRepository::candidateNodeIds(), L-06 — one
     * recursive-CTE descent per anchor). The normal actor gets exactly one anchor at
     * root, for scenario comparison (Required Reads / harness.md executor split).
     *
     * @return list<int> the many-grant actor's anchor node ids
     */
    private function seedGrants(int $rootId, array $folderNodeIds, array $profile): array
    {
        $count = min($profile['many_grant_anchors'], count($folderNodeIds));
        $anchors = $this->sampleKeys($folderNodeIds, $count);

        $rows = [];

        foreach ($anchors as $nodeId) {
            $rows[] = $this->grantRow($nodeId, self::MANY_GRANT_ACTOR_ID);
        }

        $rows[] = $this->grantRow($rootId, self::NORMAL_ACTOR_ID);

        foreach ($this->arrayChunks($rows, self::INSERT_CHUNK) as $chunk) {
            $this->connection()->table(Config::table('node_grants'))->insert($chunk);
        }

        return $anchors;
    }

    /**
     * @return array<string, mixed>
     */
    private function grantRow(int $nodeId, int $subjectId): array
    {
        $now = $this->randomPastTimestamp();

        return [
            'node_id' => $nodeId,
            'subject_id' => $subjectId,
            'subject_type' => (new TestUser)->getMorphClass(),
            'level' => GrantLevel::Reader->value,
            'permissions' => GrantLevel::Reader->bits(),
            'effect' => Effect::Allow->value,
            'scope' => GrantScope::Subtree->value,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function seedShares(array $nodeIds, array $profile): int
    {
        $count = min($profile['active'], count($nodeIds));
        $targets = $this->sampleKeys($nodeIds, $count);
        $revokedShare = $profile['revoked_share'];

        $rows = [];

        foreach ($targets as $i => $nodeId) {
            $revoked = random_int(1, 100) <= (int) round($revokedShare * 100);
            $token = Str::random(48);

            $rows[] = [
                'node_id' => $nodeId,
                'token_hash' => hash('sha256', $token),
                'token_prefix' => substr($token, 0, 8),
                'expires_at' => random_int(0, 1) === 1 ? Carbon::now()->addDays(random_int(1, 60)) : null,
                'revoked_at' => $revoked ? Carbon::now()->subDays(random_int(1, 30)) : null,
                'created_at' => $this->randomPastTimestamp(),
                'updated_at' => $this->randomPastTimestamp(),
            ];
        }

        foreach ($this->arrayChunks($rows, self::INSERT_CHUNK) as $chunk) {
            $this->connection()->table(Config::table('node_shares'))->insert($chunk);
        }

        return count($rows);
    }

    private function seedComments(array $nodeIds, array $profile): int
    {
        $count = $profile['total'];
        $morphClass = (new TestUser)->getMorphClass();
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $nodeId = $nodeIds[array_rand($nodeIds)];

            $rows[] = [
                'node_id' => $nodeId,
                'author_id' => self::SEED_OWNER_ACTOR_ID,
                'author_type' => $morphClass,
                'body' => 'load-comment-'.$i,
                'created_at' => $this->randomPastTimestamp(),
                'updated_at' => $this->randomPastTimestamp(),
            ];
        }

        foreach ($this->arrayChunks($rows, self::INSERT_CHUNK) as $chunk) {
            $this->connection()->table(Config::table('node_comments'))->insert($chunk);
        }

        return count($rows);
    }

    /**
     * A dedicated `documents/` folder under root carrying `nodes` kind=document
     * children, each with `versions_per_node` append-only v_node_document_versions
     * rows.
     *
     * @return array{nodeCount: int, versionCount: int}
     */
    private function seedDocumentVersions(int $storageId, int $rootId, array $profile): array
    {
        $folderId = $this->insertBatchReturningIds(Config::table('nodes'), [
            $this->folderRow(storageId: $storageId, parentId: $rootId, name: 'documents'),
        ])[0];

        $nodeRows = [];

        for ($i = 1; $i <= $profile['nodes']; $i++) {
            $createdAt = $this->randomPastTimestamp();

            $nodeRows[] = [
                'storage_id' => $storageId,
                'parent_id' => $folderId,
                'kind' => NodeKind::Document->value,
                'name' => 'doc-'.$i,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }

        $nodeIds = [];

        foreach ($this->arrayChunks($nodeRows, self::INSERT_CHUNK) as $chunk) {
            array_push($nodeIds, ...$this->insertBatchReturningIds(Config::table('nodes'), $chunk));
        }

        $versionRows = [];

        foreach ($nodeIds as $nodeId) {
            for ($v = 1; $v <= $profile['versions_per_node']; $v++) {
                $versionRows[] = [
                    'node_id' => $nodeId,
                    'version_no' => $v,
                    'content' => json_encode(['type' => 'doc', 'content' => []]),
                    'format' => 'tiptap',
                    'created_at' => $this->randomPastTimestamp(),
                ];
            }
        }

        foreach ($this->arrayChunks($versionRows, self::INSERT_CHUNK) as $chunk) {
            $this->connection()->table(Config::table('node_document_versions'))->insert($chunk);
        }

        return ['nodeCount' => count($nodeIds), 'versionCount' => count($versionRows)];
    }

    /**
     * `expired` sessions (status in initiated|uploading|assembled, expires_at in the
     * past — UploadSession::scopeSweepable(), L-02) PLUS `healthy` (expires_at in the
     * future — NOT swept). `multipart_share` of BOTH populations carries
     * v_upload_parts rows (protocol=s3-multipart); the rest are tus (no parts row —
     * tracked via received_bytes, Required Reads #5).
     *
     * @return array{0: array{expired: int, healthy: int}, 1: int}
     */
    private function seedUploadSessions(int $storageId, array $folderNodeIds, array $profile): array
    {
        $sessionTable = Config::table('upload_sessions');
        $partTable = Config::table('upload_parts');

        $sessionIds = [];
        $sessionRows = [];
        $isMultipart = [];

        $build = function (int $count, bool $expired) use ($storageId, $folderNodeIds, $profile, &$sessionRows, &$isMultipart): void {
            for ($i = 0; $i < $count; $i++) {
                $multipart = random_int(1, 100) <= (int) round($profile['multipart_share'] * 100);
                $isMultipart[] = $multipart;

                $sessionRows[] = [
                    'storage_id' => $storageId,
                    'upload_id' => (string) Str::uuid(),
                    'target_parent_id' => $folderNodeIds[array_rand($folderNodeIds)],
                    'target_name' => 'upload-'.Str::random(8).'.bin',
                    'protocol' => $multipart ? UploadProtocol::S3Multipart->value : UploadProtocol::Tus->value,
                    'status' => $this->randomOpenUploadStatus(),
                    'total_size_bytes' => random_int(1_048_576, 67_108_864),
                    'received_bytes' => random_int(0, 1_048_576),
                    'expires_at' => $expired
                        ? Carbon::now()->subHours(random_int(1, 72))
                        : Carbon::now()->addHours(random_int(1, 24)),
                    'created_at' => $this->randomPastTimestamp(),
                    'updated_at' => $this->randomPastTimestamp(),
                ];
            }
        };

        $build($profile['expired'], expired: true);
        $build($profile['healthy'], expired: false);

        $rowIndex = 0;

        foreach ($this->arrayChunks($sessionRows, self::INSERT_CHUNK) as $chunk) {
            array_push($sessionIds, ...$this->insertBatchReturningIds($sessionTable, $chunk));
        }

        $partRows = [];

        foreach ($sessionIds as $idx => $sessionId) {
            if (!$isMultipart[$idx]) {
                continue;
            }

            $parts = max(1, $profile['avg_parts_per_multipart_session'] + random_int(-1, 1));

            for ($p = 1; $p <= $parts; $p++) {
                $partRows[] = [
                    'upload_session_id' => $sessionId,
                    'part_no' => $p,
                    'size_bytes' => random_int(5_242_880, 67_108_864),
                    'received_at' => $this->randomPastTimestamp(),
                ];
            }
        }

        foreach ($this->arrayChunks($partRows, self::INSERT_CHUNK) as $chunk) {
            $this->connection()->table($partTable)->insert($chunk);
        }

        return [['expired' => $profile['expired'], 'healthy' => $profile['healthy']], count($partRows)];
    }

    private function randomOpenUploadStatus(): string
    {
        return [UploadStatus::Initiated->value, UploadStatus::Uploading->value, UploadStatus::Assembled->value][random_int(0, 2)];
    }

    /**
     * Small, fixed row count (tens–hundreds, never a volume knob) — real Eloquent
     * `forceFill()->save()` on purpose (WebhookEndpoint::$fillable deliberately
     * excludes `signing_secret` — the real writer,
     * WebhookEndpointStoreRepository, uses forceFill() exclusively too), so
     * `signing_secret` goes through the model's `encrypted` cast
     * instead of landing as unusable plaintext under a cast the model expects to
     * decrypt (WebhookEndpoint::casts()).
     *
     * @return list<int>
     */
    private function seedWebhookEndpoints(array $profile): array
    {
        $model = config('vaulter-webhooks.models.webhook_endpoint');
        $ids = [];

        for ($i = 1; $i <= $profile['active']; $i++) {
            $events = array_slice(self::WEBHOOK_EVENT_POOL, 0, random_int(1, count(self::WEBHOOK_EVENT_POOL)));

            /** @var WebhookEndpoint $endpoint */
            $endpoint = new $model;
            $endpoint->forceFill([
                'url' => "https://load-sink.invalid/hook/{$i}",
                'events' => $events,
                'signing_secret' => Str::random(64),
                'is_active' => true,
            ])->save();

            $ids[] = (int) $endpoint->getKey();
        }

        return $ids;
    }

    private function seedOutbox(array $profile): void
    {
        $rows = [];

        for ($i = 0; $i < $profile['backlog']; $i++) {
            $rows[] = [
                'event_id' => (string) Str::uuid(),
                'event' => self::WEBHOOK_EVENT_POOL[array_rand(self::WEBHOOK_EVENT_POOL)],
                'payload' => json_encode(['load' => true, 'seq' => $i]),
                'available_at' => Carbon::now()->subMinutes(random_int(1, 1440)),
                'status' => 'pending',
                'created_at' => $this->randomPastTimestamp(),
                'updated_at' => $this->randomPastTimestamp(),
            ];
        }

        foreach ($this->arrayChunks($rows, self::INSERT_CHUNK) as $chunk) {
            $this->connection()->table(Config::table('outbox'))->insert($chunk);
        }
    }

    private function seedWebhookDeliveries(array $endpointIds, array $profile): void
    {
        if ($endpointIds === []) {
            return;
        }

        $rows = [];

        for ($i = 0; $i < $profile['due']; $i++) {
            $rows[] = [
                'endpoint_id' => $endpointIds[array_rand($endpointIds)],
                'event' => self::WEBHOOK_EVENT_POOL[array_rand(self::WEBHOOK_EVENT_POOL)],
                'payload' => json_encode(['load' => true, 'seq' => $i]),
                'status' => random_int(1, 2) === 1 ? 'pending' : 'failed',
                'attempts' => random_int(0, 3),
                'next_retry_at' => Carbon::now()->subMinutes(random_int(1, 1440)),
                'created_at' => $this->randomPastTimestamp(),
                'updated_at' => $this->randomPastTimestamp(),
            ];
        }

        foreach ($this->arrayChunks($rows, self::INSERT_CHUNK) as $chunk) {
            $this->connection()->table(Config::table('webhook_deliveries'))->insert($chunk);
        }
    }

    /**
     * Historical backfill only — the RPS-paced live tail during a k6 run is
     * `load:activity-write` (ActivityWriterCommand), started separately.
     */
    private function seedActivity(int $storageId, array $nodeIds, array $profile): void
    {
        $verbs = ['node.created', 'file.uploaded', 'version.created', 'node.moved', 'node.trashed', 'grant.created'];
        $total = $profile['total'];
        $nodeModel = Config::nodeModel();
        $nodeMorphType = (new $nodeModel)->getMorphClass();
        $rows = [];

        for ($i = 0; $i < $total; $i++) {
            $rows[] = [
                'storage_id' => $storageId,
                'actor_id' => self::SEED_OWNER_ACTOR_ID,
                'actor_type' => (new TestUser)->getMorphClass(),
                'verb' => $verbs[array_rand($verbs)],
                'target_id' => $nodeIds[array_rand($nodeIds)],
                'target_type' => $nodeMorphType,
                'context' => json_encode(['seed_seq' => $i]),
                'created_at' => $this->randomPastTimestamp(),
            ];

            if (count($rows) >= self::INSERT_CHUNK) {
                $this->connection()->table(Config::table('activity'))->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            $this->connection()->table(Config::table('activity'))->insert($rows);
        }
    }

    /**
     * A raw multi-row INSERT ... RETURNING id, chunked to INSERT_CHUNK. Every row in
     * a single call must share the SAME column set (Laravel's grammar takes the
     * first row's keys as the column list) — a column a given row omits falls back
     * to its table DEFAULT, exactly like a normal INSERT.
     *
     * @param  list<array<string, mixed>> $rows
     * @return list<int>
     */
    private function insertBatchReturningIds(string $table, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = [];

        foreach ($this->arrayChunks($rows, self::INSERT_CHUNK) as $chunk) {
            $columns = array_keys($chunk[0]);
            $columnSql = implode(', ', array_map(static fn (string $c): string => "\"{$c}\"", $columns));

            $placeholders = [];
            $bindings = [];

            foreach ($chunk as $row) {
                $rowPlaceholders = [];

                foreach ($columns as $column) {
                    $rowPlaceholders[] = '?';
                    $bindings[] = $row[$column] instanceof Carbon ? $row[$column]->toDateTimeString() : $row[$column];
                }

                $placeholders[] = '('.implode(', ', $rowPlaceholders).')';
            }

            $sql = "INSERT INTO {$table} ({$columnSql}) VALUES ".implode(', ', $placeholders).' RETURNING id';

            foreach ($this->connection()->select($sql, $bindings) as $row) {
                $ids[] = (int) $row->id;
            }
        }

        return $ids;
    }

    /**
     * @param  list<int> $pool
     * @return list<int>
     */
    private function sampleKeys(array $pool, int $count): array
    {
        if ($count <= 0 || $pool === []) {
            return [];
        }

        $count = min($count, count($pool));
        $keys = array_rand(array_flip($pool), $count);

        return is_array($keys) ? array_values($keys) : [$keys];
    }

    /**
     * @return \Generator<int, int>
     */
    private function batchChunks(int $total): \Generator
    {
        $remaining = $total;

        while ($remaining > 0) {
            $chunk = min(self::INSERT_CHUNK, $remaining);
            yield $chunk;
            $remaining -= $chunk;
        }
    }

    /**
     * @param  list<mixed>                  $items
     * @return \Generator<int, list<mixed>>
     */
    private function arrayChunks(array $items, int $size): \Generator
    {
        foreach (array_chunk($items, $size) as $chunk) {
            yield $chunk;
        }
    }

    private function randomPastTimestamp(): Carbon
    {
        return Carbon::now()->subDays(random_int(0, 90))->subSeconds(random_int(0, 86_400));
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection(Config::connection());
    }
}

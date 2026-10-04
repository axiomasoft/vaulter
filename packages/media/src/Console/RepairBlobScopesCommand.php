<?php

declare(strict_types=1);

namespace Vaulter\Media\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;
use Vaulter\Cas\BlobLock;
use Vaulter\Contracts\Cas\ContentStore;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Models\Cas\Blob;
use Vaulter\Repositories\Cas\BlobStoreRepository;
use Vaulter\Support\Config;
use Vaulter\ValueObjects\Cas\BlobScope;

final class RepairBlobScopesCommand extends Command
{
    protected $signature = 'vaulter:blobs:repair-scopes {--dry-run} {--execute}';

    protected $description = 'Copy legacy mixed-scope blobs to their lossless scopes and atomically redirect revision edges';

    public function handle(VaulterDatabase $database, ContentStore $content, BlobStoreRepository $blobs, BlobLock $lock): int
    {
        if ((bool) $this->option('dry-run') === (bool) $this->option('execute')) {
            $this->error('Select exactly one of --dry-run or --execute.');

            return self::INVALID;
        }

        $repaired = 0;
        $skipped = 0;
        $failed = false;
        $model = Config::blobModel();
        $model::query()->whereIn('scope_key', ['legacy:snull', 'legacy:tnull'])->chunkById(100, function ($batch) use ($database, $content, $blobs, $lock, &$repaired, &$skipped, &$failed): void {
            $rows = [];
            foreach ($batch as $blob) {
                $references = $this->referenceRows($database, $blob);
                $groups = $references->groupBy(function ($ref) use ($blob): string {
                    return $this->target($blob, (string) $ref->storage_id, $ref->tenant_id === null ? null : (string) $ref->tenant_id)->key();
                });
                if ($groups->isEmpty()) {
                    $rows[] = [$blob->getKey(), $blob->sha256, $blob->scope_key, '—', 0, '—', 'unreferenced'];
                    $skipped++;
                }

                foreach ($groups as $refs) {
                    $first = $refs->first() ?? throw new RuntimeException('Missing scope repair reference.');
                    $scope = $this->target($blob, (string) $first->storage_id, $first->tenant_id === null ? null : (string) $first->tenant_id);
                    $action = 'move';
                    try {
                        if ($this->option('execute')) {
                            $action = $this->repair($database, $content, $blobs, $lock, $blob, $scope, array_values(array_unique($refs->map(static fn ($ref): string => (string) $ref->storage_id)->all())));
                            $action === 'move' ? $repaired++ : $skipped++;
                        } elseif (!$this->validObject($content, $blob->storage_key, $blob->disk, $blob->sha256)) {
                            $action = 'hash_mismatch';
                            $skipped++;
                        }
                    } catch (Throwable $exception) {
                        $action = 'error';
                        $failed = true;
                        $skipped++;
                        $this->error($exception->getMessage());
                    }
                    $rows[] = [$blob->getKey(), $blob->sha256, $blob->scope_key, $refs->pluck('storage_id')->unique()->implode(','), $refs->count(), $scope->key(), $action];
                }
            }
            $this->table(['blob_id', 'sha256', 'scope_key', 'storage_id', 'refs', 'target', 'action'], $rows);
        });
        $this->info("Repaired: {$repaired}, skipped: {$skipped}");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<string>|null                                                                                   $storageIds
     * @return Collection<int, object{version_id: int|string, storage_id: int|string, tenant_id: int|string|null}>
     */
    private function referenceRows(VaulterDatabase $database, Blob $blob, ?array $storageIds = null): Collection
    {
        $query = $this->references($database, $blob);
        if ($storageIds !== null) {
            $query->whereIn('storages.id', $storageIds);
        }
        /** @var Collection<int, object{version_id: int|string, storage_id: int|string, tenant_id: int|string|null}> $rows */
        $rows = $query->get();

        return $rows;
    }

    private function references(VaulterDatabase $database, Blob $blob): Builder
    {
        return $database->connection()->table(Config::table('file_version_blobs').' as edges')
            ->join(Config::table('file_versions').' as versions', 'versions.id', '=', 'edges.file_version_id')
            ->join(Config::table('files').' as files', 'files.id', '=', 'versions.file_id')
            ->join(Config::table('nodes').' as nodes', 'nodes.id', '=', 'files.node_id')
            ->join(Config::table('storages').' as storages', 'storages.id', '=', 'nodes.storage_id')
            ->where('edges.blob_id', $blob->getKey())
            ->select(['versions.id as version_id', 'storages.id as storage_id', 'storages.tenant_id']);
    }

    private function target(Blob $blob, string $storageId, ?string $tenantId): BlobScope
    {
        return $blob->scope_key === 'legacy:snull' ? BlobScope::drive($storageId)
            : ($tenantId === null ? BlobScope::global() : BlobScope::tenant($tenantId));
    }

    /** @param list<string> $storageIds */
    private function repair(VaulterDatabase $database, ContentStore $content, BlobStoreRepository $blobs, BlobLock $lock, Blob $candidate, BlobScope $scope, array $storageIds): string
    {
        return $database->transaction(function () use ($database, $content, $blobs, $lock, $candidate, $scope, $storageIds): string {
            // Follow the tree-before-CAS order so uploads and purge cannot change
            // this revision chain while the repair redirects its edges.
            sort($storageIds, SORT_STRING);
            foreach ($storageIds as $id) {
                $database->lockTree($id);
            }
            $keys = ['cas:'.$candidate->scope_key.':'.$candidate->sha256, 'cas:'.$scope->key().':'.$candidate->sha256];
            $lock->acquireMany($keys);
            if ($lock->usesRowLock()) {
                // Match GC's primary-key scan before the repository locks either
                // row individually. Force the index to avoid a filesort lock order.
                $model = Config::blobModel();
                $model::query()->where('sha256', $candidate->sha256)
                    ->whereIn('scope_key', [$candidate->scope_key, $scope->key()])
                    ->orderBy((new $model)->getKeyName())->forceIndex('PRIMARY')->lockForUpdate()->get();
            }
            $old = $blobs->lockForUpdate($candidate);
            $refs = $this->referenceRows($database, $old, $storageIds)
                ->filter(fn ($ref): bool => $this->target($old, (string) $ref->storage_id, $ref->tenant_id === null ? null : (string) $ref->tenant_id)->key() === $scope->key());
            if ($refs->isEmpty()) {
                return 'unreferenced';
            }

            $stream = $content->get($old->storage_key, $old->disk);
            if (!is_resource($stream)) {
                return 'hash_mismatch';
            }
            try {
                $staged = $content->stage($stream);
            } finally {
                fclose($stream);
            }
            try {
                if (!hash_equals($old->sha256, $staged->sha256())) {
                    return 'hash_mismatch';
                }
                $result = $blobs->findOrCreate($old->sha256, $staged->sizeBytes(), $content->keyFor($scope, $old->sha256), $old->disk, $scope, $scope->tenantId(), $scope->storageId());
                $target = $result['blob'];
                $content->promote($staged, $target->storage_key, $target->disk);
                if (!$this->validObject($content, $target->storage_key, $target->disk, $old->sha256)) {
                    throw new RuntimeException('Repair target bytes failed SHA-256 verification.');
                }
                $db = $database->connection();
                $edges = $db->table(Config::table('file_version_blobs'));
                $count = $edges->whereIn('file_version_id', $refs->pluck('version_id')->unique()->all())->where('blob_id', $old->getKey())->update(['blob_id' => $target->getKey()]);
                $db->table(Config::table('file_versions'))->whereIn('id', $refs->pluck('version_id')->unique()->all())
                    ->where('blob_id', $old->getKey())->update(['blob_id' => $target->getKey()]);
                // ref_count is a cache: repair from live edges rather than decrement
                // a possibly stale cache below zero.
                foreach ([$old, $target] as $blob) {
                    $blobs->setRefCount($blob, $db->table(Config::table('file_version_blobs'))->where('blob_id', $blob->getKey())->count());
                }
                $entry = json_encode(['ts' => now()->toIso8601String(), 'blob_id' => $old->getKey(), 'target' => $scope->key(), 'action' => 'move', 'edges' => $count], JSON_THROW_ON_ERROR)."\n";
                $database->afterCommit(static function () use ($entry): void {
                    $dir = storage_path('logs');
                    if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
                        throw new RuntimeException('Cannot create scope repair log directory.');
                    }
                    if (file_put_contents($dir.'/vaulter-repair-scopes.log', $entry, FILE_APPEND | LOCK_EX) === false) {
                        throw new RuntimeException('Cannot append scope repair log.');
                    }
                });

                return 'move';
            } finally {
                $staged->discard();
            }
        });
    }

    private function validObject(ContentStore $content, string $key, string $disk, string $sha256): bool
    {
        $stream = $content->get($key, $disk);
        if (!is_resource($stream)) {
            return false;
        }
        try {
            $hash = hash_init('sha256');
            while (!feof($stream)) {
                $buffer = fread($stream, 1_048_576);
                if ($buffer === false) {
                    return false;
                }
                hash_update($hash, $buffer);
            }

            return hash_equals($sha256, hash_final($hash));
        } finally {
            fclose($stream);
        }
    }
}

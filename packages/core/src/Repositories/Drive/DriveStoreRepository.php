<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Drive;

use Vaulter\Attributes\Internal;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Enums\Drive\DriveStatus;
use Vaulter\Exceptions\Drive\QuotaExceededException;
use Vaulter\Models\Drive\Drive;

/**
 * Write-side row work for v_drives. Bare create + the byte-accounting
 * mutations (used_bytes delta, root pointer). Opens NO transactions — the
 * StorageManager / Drive Actions wrap it. used_bytes is the EAGER quota cache
 * incremented within the upload transaction, decremented only on
 * forceDelete (trashed bytes still occupy storage).
 */
#[Internal]
final class DriveStoreRepository
{
    public function __construct(
        private readonly VaulterConfig $vaulterConfig,
        private readonly VaulterDatabase $database,
    ) {}

    /** Reserve head bytes atomically inside the caller's transaction. */
    public function reserveBytes(Drive $storage, int $delta): void
    {
        if ($delta <= 0) {
            $this->adjustUsedBytes($storage, $delta);

            return;
        }

        $connection = $this->database->connection();
        $grammar = $connection->getQueryGrammar();
        $table = $grammar->wrapTable($storage->getTable());
        $key = $grammar->wrap($storage->getKeyName());
        $updated = $connection->update(
            "update {$table} set used_bytes = used_bytes + ? where {$key} = ? and (quota_bytes is null or used_bytes + ? <= quota_bytes)",
            [$delta, $storage->getKey(), $delta],
        );

        $storage->refresh();

        if ($updated === 0) {
            throw QuotaExceededException::forStorage(
                storageId: $storage->id,
                quotaBytes: $storage->quota_bytes ?? 0,
                usedBytes: $storage->used_bytes,
                incomingBytes: $delta,
            );
        }
    }

    /** The drive row, locked for the rest of the caller's transaction. */
    public function lock(int|string $id): Drive
    {
        $model = $this->vaulterConfig->model('drive');

        return $model::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /** Lifecycle transition; the caller holds the row lock and has checked the source status. */
    public function setStatus(Drive $drive, DriveStatus $status): Drive
    {
        $drive->forceFill(['status' => $status])->save();

        return $drive;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): Drive
    {
        // forceFill: $attributes is a server-built literal array
        // from StorageManager, never raw request input.
        $model = $this->vaulterConfig->model('drive');
        $storage = new $model;
        $storage->forceFill($attributes);
        $storage->save();

        return $storage;
    }

    /**
     * Point a freshly created storage at its root folder node once the
     * node exists — the chicken-and-egg insert order the schema deliberately
     * leaves without a hard FK.
     */
    public function setRootNode(Drive $storage, int|string $rootNodeId): Drive
    {
        $storage->forceFill(['root_node_id' => $rootNodeId])->save();

        return $storage;
    }

    /**
     * Adjust the used_bytes quota cache by a signed delta (eager quota),
     * clamped at zero so a reconciliation drift can never drive it negative. Runs
     * inside the caller's upload/force-delete transaction.
     *
     * The delta is applied as a single atomic UPDATE off the column's CURRENT
     * row value (`used_bytes = max(0, used_bytes + ?)`), NOT off the possibly
     * stale in-memory model — a read-modify-write here would silently clobber a
     * concurrent upload/force-delete committing in another transaction and drift
     * the cache that gates uploads. The CASE clamp is portable across SQLite /
     * Postgres / MySQL (no GREATEST). The in-memory model is then re-hydrated so
     * callers observe the post-update value.
     */
    public function adjustUsedBytes(Drive $storage, int $delta): Drive
    {
        $connection = $this->database->connection();
        $grammar = $connection->getQueryGrammar();
        $table = $grammar->wrapTable($storage->getTable());
        $key = $grammar->wrap($storage->getKeyName());

        $connection->update(
            query: sprintf(
                'update %s set used_bytes = case when used_bytes + ? < 0 then 0 else used_bytes + ? end where %s = ?',
                $table,
                $key,
            ),
            bindings: [$delta, $delta, $storage->getKey()],
        );

        return $storage->refresh();
    }
}

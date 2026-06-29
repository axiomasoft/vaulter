<?php

declare(strict_types=1);

namespace Vaulter\Repositories;

use Vaulter\Models\Storage;
use Vaulter\Support\Config;

/**
 * Write-side row work for v_storages (§2.1). Bare create + the byte-accounting
 * mutations (used_bytes delta, root pointer). Opens NO transactions — the
 * StorageManager / Drive Actions wrap it. used_bytes is the EAGER quota cache
 * (§1.5): incremented within the upload transaction, decremented only on
 * forceDelete (trashed bytes still occupy storage).
 */
final class StorageStoreRepository
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): Storage
    {
        $model = Config::storageModel();
        $storage = new $model;
        $storage->fill($attributes);
        $storage->save();

        return $storage;
    }

    /**
     * Point a freshly created storage at its root folder node (§2.1) once the
     * node exists — the chicken-and-egg insert order the schema deliberately
     * leaves without a hard FK.
     */
    public function setRootNode(Storage $storage, int|string $rootNodeId): Storage
    {
        $storage->forceFill(['root_node_id' => $rootNodeId])->save();

        return $storage;
    }

    /**
     * Adjust the used_bytes quota cache by a signed delta (§1.5 eager quota),
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
    public function adjustUsedBytes(Storage $storage, int $delta): Storage
    {
        $table = $storage->getTable();
        $key = $storage->getKeyName();

        $storage->getConnection()->update(
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

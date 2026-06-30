<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Drive;

use Illuminate\Database\Eloquent\Model;
use Vaulter\Models\Drive\Storage;
use Vaulter\Support\Config;

/**
 * Read-side lookups over v_storages (§2.1). The get-or-create entry point is the
 * StorageManager; this repository just resolves the existing row for a host
 * entity within a panel (the idempotency key behind the partial-unique index on
 * owner_type/owner_id/panel).
 */
final class StorageReadRepository
{
    public function find(int|string $id): ?Storage
    {
        $model = Config::storageModel();

        return $model::query()->find($id);
    }

    /**
     * The storage mounted on a host entity within a panel (§2.1) — the
     * polymorphic owner triple (owner_type, owner_id, panel) is the idempotency
     * key. Returns null when none exists yet (the manager then provisions one).
     */
    public function forOwner(Model $owner, string $panel): ?Storage
    {
        $model = Config::storageModel();

        return $model::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('panel', $panel)
            ->first();
    }
}

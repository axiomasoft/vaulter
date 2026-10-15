<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Drive;

use Illuminate\Database\Eloquent\Model;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Models\Drive\Drive;
use Vaulter\Support\Identity;
use Vaulter\Tenancy\TenancyBoundary;

/**
 * Read-side lookups over v_drives. The get-or-create entry point is the
 * StorageManager; this repository just resolves the existing row for a host
 * entity within a panel (the idempotency key behind the partial-unique index on
 * owner_type/owner_id/panel).
 *
 * SCOPED-singleton bound — `find()` memoizes by id in an
 * in-request identity map, so a page of N sibling nodes belonging to the SAME
 * storage (the common case: `AccessResolver::storageOf()` calls this once per
 * node via `abilitiesFor()`) issues exactly ONE `SELECT` for that storage, not N.
 * `scoped()` (not `singleton()`) so the cache resets between requests/jobs on
 * Octane/queue workers rather than leaking across them.
 */
#[Internal]
final class DriveReadRepository
{
    public function __construct(
        private readonly VaulterConfig $vaulterConfig,
    ) {}

    /** @var array<string, Drive|null> */
    private array $cache = [];

    public function find(int|string $id): ?Drive
    {
        if (app(TenancyBoundary::class)->enforced()) {
            $model = $this->vaulterConfig->model('drive');

            return app(TenancyBoundary::class)->constrain($model::query())->find($id);
        }
        $key = Identity::stringify($id);

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $model = $this->vaulterConfig->model('drive');

        return $this->cache[$key] = $model::query()->find($id);
    }

    /**
     * The storage mounted on a host entity within a panel — the
     * polymorphic owner triple (owner_type, owner_id, panel) is the idempotency
     * key. Returns null when none exists yet (the manager then provisions one).
     */
    public function forOwner(Model $owner, string $panel): ?Drive
    {
        $model = $this->vaulterConfig->model('drive');

        $owner = app(TenancyBoundary::class)->assertOwner($owner);
        $drive = $model::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('profile', $panel)
            ->first();

        return $drive === null ? null : app(TenancyBoundary::class)->assertDrive($drive);
    }
}

<?php

declare(strict_types=1);

namespace Vaulter\Tenancy;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Tenancy\ResolvesOwnerTenant;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Tenancy\TenancyMisconfiguredException;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Identity;

/** @internal Central boundary; resolver values and supplied models are never cached. */
#[Internal]
final readonly class TenancyBoundary
{
    public function __construct(private TenancyConfig $config, private VaulterConfig $models,
        private Container $container, private TenancyBypass $bypass) {}

    public static function normalize(int|string|null $tenant): ?string
    {
        return $tenant === null ? null : (string) $tenant;
    }

    public function enforced(): bool
    {
        return $this->config->enabled() && !$this->bypass->active();
    }

    public function current(): string
    {
        $shareDrive = $this->container->make(ShareTenantContext::class)->drive();
        $current = self::normalize($shareDrive === null ? $this->container->make(TenantResolver::class)->current() : $shareDrive->tenant_id);
        if ($current === null) {
            throw new TenancyMisconfiguredException('Strict tenancy requires a current tenant.');
        }

        return $current;
    }

    public function assertDrive(Drive $drive): Drive
    {
        $key = $drive->getRawOriginal($drive->getKeyName());
        $class = $this->models->model('drive');
        $fresh = $this->constrain($class::query())->whereKey($key)->first();
        if (!$fresh instanceof Drive) {
            throw NodeNotFoundException::forId('drive');
        }

        return $fresh;
    }

    public function assertOwner(Model $owner): Model
    {
        if (!$this->config->enabled()) {
            return $owner;
        }
        $current = $this->enforced() ? $this->current() : null;
        $resolver = $this->container->make(TenantResolver::class);
        if (!$resolver instanceof ResolvesOwnerTenant) {
            return $owner;
        }
        $key = $owner->getRawOriginal($owner->getKeyName());
        $suppliedKey = $owner->getKey();
        if (!$owner->exists || (!is_int($key) && !is_string($key))
            || (!is_int($suppliedKey) && !is_string($suppliedKey))
            || self::normalize($key) !== self::normalize($suppliedKey)) {
            throw NodeNotFoundException::forId('owner');
        }
        $fresh = $owner->newQuery()->whereKey($key)->first();
        $freshKey = $fresh?->getKey();
        if ($fresh === null || $fresh->getMorphClass() !== $owner->getMorphClass()
            || (!is_int($freshKey) && !is_string($freshKey)) || self::normalize($freshKey) !== self::normalize($key)
            || ($this->enforced() && self::normalize($resolver->tenantOf($fresh)) !== $current)) {
            throw NodeNotFoundException::forId('owner');
        }

        return $fresh;
    }

    /**
     * assertNode() for a whole page: the same checks from one read of the nodes, one read
     * of their drives within the current tenant, instead of three reads per node.
     *
     * @param  list<Node>           $nodes
     * @return array<string, Drive> node key => the node's drive
     */
    public function assertNodes(array $nodes): array
    {
        $keys = [];
        foreach ($nodes as $node) {
            $key = $node->getRawOriginal($node->getKeyName());
            if (!is_int($key) && !is_string($key)) {
                throw NodeNotFoundException::forId('node');
            }
            $keys[(string) $key] = $key;
        }
        if ($keys === []) {
            return [];
        }
        $class = $this->models->model('node');
        $fresh = [];
        foreach ($class::query()->whereKey(array_values($keys))->get() as $row) {
            $fresh[Identity::stringify($row->getRawOriginal($row->getKeyName()))] = $row;
        }
        $driveIds = [];
        foreach ($fresh as $row) {
            $driveIds[(string) $row->drive_id] = $row->drive_id;
        }
        $driveClass = $this->models->model('drive');
        $drives = [];
        foreach ($this->constrain($driveClass::query())->whereKey(array_values($driveIds))->get() as $drive) {
            $drives[Identity::stringify($drive->getRawOriginal($drive->getKeyName()))] = $drive;
        }
        $result = [];
        foreach (array_keys($keys) as $key) {
            $row = $fresh[$key] ?? throw NodeNotFoundException::forId('node');
            $drive = $drives[(string) $row->drive_id] ?? throw NodeNotFoundException::forId('drive');
            if ($this->config->enabled() && self::normalize($row->tenant_id) !== self::normalize($drive->tenant_id)) {
                throw NodeNotFoundException::forId('node');
            }
            $result[$key] = $drive;
        }

        return $result;
    }

    /** @param iterable<Node|\stdClass> $nodes Already fetched adjacency rows. */
    public function assertTree(iterable $nodes, Drive $drive): void
    {
        if (!$this->config->enabled()) {
            return;
        }
        $drive = $this->assertDrive($drive);
        foreach ($nodes as $node) {
            $tenant = $node->tenant_id ?? null;
            $driveId = $node->drive_id;
            if (($tenant !== null && !is_int($tenant) && !is_string($tenant))
                || (!is_int($driveId) && !is_string($driveId)) || self::normalize($driveId) !== self::normalize($drive->id)
                || self::normalize($tenant) !== self::normalize($drive->tenant_id)) {
                throw NodeNotFoundException::forId('node');
            }
        }
    }

    public function assertNode(Node $node): Drive
    {
        $class = $this->models->model('node');
        $fresh = $class::query()->whereKey($node->getRawOriginal($node->getKeyName()))->first();
        if (!$fresh instanceof Node) {
            throw NodeNotFoundException::forId('node');
        }
        $driveClass = $this->models->model('drive');
        $drive = $driveClass::query()->whereKey($fresh->drive_id)->first();
        if (!$drive instanceof Drive) {
            throw NodeNotFoundException::forId('drive');
        }
        $drive = $this->assertDrive($drive);
        if ($this->config->enabled() && self::normalize($fresh->tenant_id) !== self::normalize($drive->tenant_id)) {
            throw NodeNotFoundException::forId('node');
        }

        return $drive;
    }

    /** @template T of Model
     * @param  Builder<T> $query
     * @return Builder<T>
     */
    public function constrain(Builder $query, ?string $driveRelation = null): Builder
    {
        if (!$this->enforced()) {
            return $query;
        }
        $tenant = $this->current();
        $shareDrive = $this->container->make(ShareTenantContext::class)->drive();
        if ($shareDrive !== null) {
            if ($query->getModel() instanceof Drive) {
                $query->whereKey($shareDrive->getRawOriginal($shareDrive->getKeyName()));
            } elseif ($driveRelation !== null) {
                $query->whereHas($driveRelation, fn (Builder $drive): Builder => $drive->whereKey($shareDrive->getRawOriginal($shareDrive->getKeyName())));
            } else {
                $query->where($query->getModel()->qualifyColumn('drive_id'), $shareDrive->getRawOriginal($shareDrive->getKeyName()));
            }
        }
        if ($query->getModel() instanceof Drive) {
            return $this->tenantPredicate($query, $tenant);
        }
        $this->tenantPredicate($query, $tenant);
        if ($driveRelation !== null) {
            return $query->whereHas($driveRelation, fn (Builder $drive): Builder => $this->tenantPredicate($drive, $tenant));
        }
        $driveClass = $this->models->model('drive');

        return $query->whereIn($query->getModel()->qualifyColumn('drive_id'),
            $this->tenantPredicate($driveClass::query()->select('id'), $tenant));
    }

    /** @template T of Model
     * @param  Builder<T> $query
     * @return Builder<T>
     */
    private function tenantPredicate(Builder $query, string $tenant): Builder
    {
        $type = $this->models->ids()->hostKeys();
        if (($type === 'bigint' && filter_var($tenant, FILTER_VALIDATE_INT) === false)
            || ($type === 'uuid' && preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $tenant) !== 1)) {
            return $query->whereRaw('1 = 0');
        }
        $column = $query->getModel()->qualifyColumn('tenant_id');
        // Keep the indexed predicate, then reject numeric coercion, collations,
        // CHAR padding and case folding using exact normalized string identity.
        $query->where($column, $tenant);

        return $query->whereRaw(new TenantIdentityPredicate($column, $query->getModel()->getConnection()->getDriverName()), [$tenant]);
    }

    /** Queue authority always ignores a surrounding maintenance closure. */
    public function assertJobDrive(int|string $driveId, int|string|null $tenantId): Drive
    {
        $class = $this->models->model('drive');
        $query = $class::query()->whereKey($driveId);
        if ($this->config->enabled()) {
            $current = self::normalize($this->container->make(TenantResolver::class)->current());
            if ($current === null) {
                throw new TenancyMisconfiguredException('Strict queue execution requires a current tenant.');
            }
            if ($tenantId === null || self::normalize($tenantId) !== $current) {
                throw NodeNotFoundException::forId('drive');
            }
            $this->tenantPredicate($query, $current);
        }
        $drive = $query->first();
        if (!$drive instanceof Drive) {
            throw NodeNotFoundException::forId('drive');
        }

        return $drive;
    }
}

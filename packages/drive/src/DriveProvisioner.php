<?php

declare(strict_types=1);

namespace Vaulter\Drive;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Content\ScanPolicyResolver;
use Vaulter\Drive\Events\Drives\DriveCreated;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Media\Configuration\MediaConfig;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\OperationContext;
use Vaulter\Profiles\ProfilePolicy;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Repositories\Drive\DriveReadRepository;
use Vaulter\Repositories\Drive\DriveStoreRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Tenancy\TenancyBoundary;
use Vaulter\ValueObjects\Access\Actor;

/**
 * Get-or-create a storage container for a host entity within a panel (§2.1), with
 * automatic root-folder provisioning. The polymorphic owner triple
 * (owner_type, owner_id, panel) is the idempotency key — guarded by the partial
 * UNIQUE index defineStorages adds, so concurrent first-touches converge on one
 * row (the INSERT loser catches the unique violation and re-reads).
 *
 * The storage's defaults come from the panel: disk from config storage.default_disk
 * and storage_type from the resolved Panel. A fresh storage gets a kind=folder
 * root node, and root_node_id is repointed at it once it exists (the schema leaves
 * that pair without a hard FK to dodge the chicken-and-egg insert order).
 */
final readonly class DriveProvisioner
{
    public function __construct(
        private DriveReadRepository $read,
        private DriveStoreRepository $store,
        private NodeStoreRepository $nodes,
        private VaulterDatabase $database,
        private MediaConfig $mediaConfig,
        private ProfileRegistry $profiles,
        private ProfilePolicy $policy,
        private DriveConfig $config,
    ) {}

    /**
     * Resolve (or provision) the storage mounted on $owner within $panel.
     * Idempotent: a second call returns the same row.
     */
    public function forOwner(Model $owner, string $panel = 'default'): Drive
    {
        $owner = app(TenancyBoundary::class)->assertOwner($owner);
        $profile = $this->profiles->get($panel);
        $this->policy->assertOwner(profile: $profile, owner: $owner);
        $existing = $this->read->forOwner(owner: $owner, panel: $panel);

        if ($existing instanceof Drive) {
            return $existing;
        }

        try {
            return $this->provision(owner: $owner, panel: $panel);
        } catch (UniqueConstraintViolationException) {
            // Lost the create race — the (owner_type, owner_id, panel) unique index
            // is the backbone; re-read the winner.
            $winner = $this->read->forOwner(owner: $owner, panel: $panel);

            if ($winner instanceof Drive) {
                return $winner;
            }

            throw NodeNotFoundException::forId('drive');
        }
    }

    /**
     * Create the storage row + its root folder node in one transaction and repoint
     * root_node_id at the node.
     */
    private function provision(Model $owner, string $panel): Drive
    {
        return $this->database->transaction(function () use ($owner, $panel): Drive {
            $attributes = [
                'profile' => $panel,
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'name' => $this->defaultName($owner),
                'disk' => $this->profiles->get($panel)->getDisk() ?? $this->mediaConfig->disk(),
                'quota_bytes' => $this->profiles->get($panel)->getDefaultQuota() ?? $this->config->defaultQuota(),
                'used_bytes' => 0,
            ];
            if (app(TenancyBoundary::class)->enforced()) {
                $attributes['tenant_id'] = app(TenancyBoundary::class)->current();
            }
            $storage = $this->store->create($attributes);

            app(ScanPolicyResolver::class)->initialize($storage, OperationContext::system($storage, 'drive provisioning'));

            $root = $this->nodes->create([
                'drive_id' => $storage->id,
                'parent_id' => null,
                'kind' => NodeKind::Folder,
                'name' => '',
                'size_bytes' => 0,
            ]);

            $this->store->setRootNode(storage: $storage, rootNodeId: $root->id);

            event(new DriveCreated(storage: $storage->refresh(), root: $root, actor: Actor::system('drive provisioning')->ref(), correlationId: (string) Str::ulid()));

            return $storage;
        });
    }

    /**
     * Resolve the root folder node of a storage, provisioning a missing one
     * (defensive — a storage created outside this manager may lack a root).
     */
    public function root(Drive $storage): Node
    {
        $storage = app(TenancyBoundary::class)->assertDrive($storage);
        $root = $storage->rootNode()->first();

        if ($root instanceof Node) {
            return $root;
        }

        return $this->database->transaction(function () use ($storage): Node {
            $this->database->lockTree($storage->id);
            $class = app(VaulterConfig::class)->model('drive');
            $storage = $class::query()->whereKey($storage->getRawOriginal($storage->getKeyName()))->lockForUpdate()->firstOrFail();
            $storage = app(TenancyBoundary::class)->assertDrive($storage);
            $existing = $storage->rootNode()->first();
            if ($existing instanceof Node) {
                return $existing;
            }
            $root = $this->nodes->create([
                'drive_id' => $storage->id,
                'parent_id' => null,
                'kind' => NodeKind::Folder,
                'name' => '',
                'size_bytes' => 0,
            ]);

            $this->store->setRootNode(storage: $storage, rootNodeId: $root->id);

            return $root;
        });
    }

    private function defaultName(Model $owner): string
    {
        return class_basename($owner).' storage';
    }
}

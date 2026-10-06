<?php

declare(strict_types=1);

namespace Vaulter\Drive;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Events\Drives\DriveCreated;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Media\Configuration\MediaConfig;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Profiles\ProfilePolicy;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Repositories\Drive\DriveReadRepository;
use Vaulter\Repositories\Drive\DriveStoreRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;

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

            throw new \RuntimeException(
                'Lost the storage provisioning race but the winning row is not visible.',
            );
        }
    }

    /**
     * Create the storage row + its root folder node in one transaction and repoint
     * root_node_id at the node.
     */
    private function provision(Model $owner, string $panel): Drive
    {
        return $this->database->transaction(function () use ($owner, $panel): Drive {
            $storage = $this->store->create([
                'profile' => $panel,
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'name' => $this->defaultName($owner),
                'disk' => $this->profiles->get($panel)->getDisk() ?? $this->mediaConfig->disk(),
                'quota_bytes' => $this->profiles->get($panel)->getDefaultQuota() ?? $this->config->defaultQuota(),
                'used_bytes' => 0,
            ]);

            $root = $this->nodes->create([
                'drive_id' => $storage->id,
                'parent_id' => null,
                'kind' => NodeKind::Folder,
                'name' => '',
                'size_bytes' => 0,
            ]);

            $this->store->setRootNode(storage: $storage, rootNodeId: $root->id);

            event(new DriveCreated(storage: $storage->refresh(), root: $root));

            return $storage;
        });
    }

    /**
     * Resolve the root folder node of a storage, provisioning a missing one
     * (defensive — a storage created outside this manager may lack a root).
     */
    public function root(Drive $storage): Node
    {
        $root = $storage->rootNode()->first();

        if ($root instanceof Node) {
            return $root;
        }

        return $this->database->transaction(function () use ($storage): Node {
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

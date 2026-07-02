<?php

declare(strict_types=1);

namespace Vaulter\Drive;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Vaulter\Drive\Events\StorageCreated;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage;
use Vaulter\Panels\PanelRegistry;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Repositories\Drive\StorageReadRepository;
use Vaulter\Repositories\Drive\StorageStoreRepository;
use Vaulter\Support\Config;

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
final readonly class StorageManager
{
    public function __construct(
        private StorageReadRepository $read,
        private StorageStoreRepository $store,
        private NodeStoreRepository $nodes,
    ) {}

    /**
     * Resolve (or provision) the storage mounted on $owner within $panel.
     * Idempotent: a second call returns the same row.
     */
    public function forOwner(Model $owner, string $panel = 'default'): Storage
    {
        $existing = $this->read->forOwner(owner: $owner, panel: $panel);

        if ($existing instanceof Storage) {
            return $existing;
        }

        try {
            return $this->provision(owner: $owner, panel: $panel);
        } catch (UniqueConstraintViolationException) {
            // Lost the create race — the (owner_type, owner_id, panel) unique index
            // is the backbone; re-read the winner.
            $winner = $this->read->forOwner(owner: $owner, panel: $panel);

            if ($winner instanceof Storage) {
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
    private function provision(Model $owner, string $panel): Storage
    {
        return DB::transaction(function () use ($owner, $panel): Storage {
            $storage = $this->store->create([
                'panel' => $panel,
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'name' => $this->defaultName($owner),
                'disk' => Config::defaultDisk(),
                'storage_type' => $this->storageType($panel),
                'used_bytes' => 0,
            ]);

            $root = $this->nodes->create([
                'storage_id' => $storage->id,
                'parent_id' => null,
                'kind' => NodeKind::Folder,
                'name' => '',
                'size_bytes' => 0,
            ]);

            $this->store->setRootNode(storage: $storage, rootNodeId: $root->id);

            event(new StorageCreated(storage: $storage->refresh(), root: $root));

            return $storage;
        });
    }

    /**
     * Resolve the root folder node of a storage, provisioning a missing one
     * (defensive — a storage created outside this manager may lack a root).
     */
    public function root(Storage $storage): Node
    {
        $root = $storage->rootNode()->first();

        if ($root instanceof Node) {
            return $root;
        }

        return DB::transaction(function () use ($storage): Node {
            $root = $this->nodes->create([
                'storage_id' => $storage->id,
                'parent_id' => null,
                'kind' => NodeKind::Folder,
                'name' => '',
                'size_bytes' => 0,
            ]);

            $this->store->setRootNode(storage: $storage, rootNodeId: $root->id);

            return $root;
        });
    }

    private function storageType(string $panel): string
    {
        return app(PanelRegistry::class)->get($panel)->storageTypeKey();
    }

    private function defaultName(Model $owner): string
    {
        return class_basename($owner).' storage';
    }
}

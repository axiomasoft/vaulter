<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions;

use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Events\NodeForceDeleted;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Nodes\NodeNotTrashedException;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\FileRevisionBlob;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\DriveStoreRepository;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\TreeGuard;

/**
 * Permanently soft-delete a trashed node and its subtree (§2.10 stage 2). Only a
 * node already in the trash qualifies (a live node goes through the trash first)
 * and the root never does; the subtree is read under the storage tree lock. The
 * order matters and is the GC contract (§2.3 / §2.4):
 *   1. detach every version↔blob edge in the subtree — the edge IS the liveness
 *      truth (H.3), so dropping it makes the blob GC-eligible; the bytes are
 *      NEVER deleted here (M1b's two-phase GC reclaims them);
 *   2. soft-delete (deleted_at) the node rows in one bulk UPDATE;
 *   3. decrement used_bytes by the freed LIVE size (§1.5 — trash kept the bytes;
 *      forceDelete is where the quota is actually reclaimed).
 *
 * Authorization (M2a / H.2) is enforced upstream at the HTTP controller
 * boundary (NodeController + AccessResolver); this Action stays pure.
 */
final readonly class ForceDeleteNodeAction
{
    public function __construct(
        private NodeReadRepository $nodes,
        private NodeStoreRepository $store,
        private DriveStoreRepository $storageStore,
        private BlobStore $blobs,
        private VaulterDatabase $database,
        private TreeGuard $tree,
    ) {}

    public function execute(Node $node, int|string|null $actorId = null): void
    {
        $storage = $node->storage;

        $this->database->transaction(function () use ($node, $storage, $actorId): void {
            $this->database->lockTree($node->storage_id);

            $current = $this->tree->sync($node, $this->tree->locked($this->tree->lock([$node->id]), $node->id));

            $this->tree->assertNotRoot(node: $current, operation: 'delete');

            if ($current->trashed_at === null) {
                throw NodeNotTrashedException::forNode($current);
            }

            $subtree = $this->nodes->subtree($current);
            $ids = $subtree->modelKeys();
            $freedBytes = 0;

            foreach ($subtree as $member) {
                if ($member->kind !== NodeKind::File) {
                    continue;
                }

                $freedBytes += $this->detachFilePayload($member);
            }

            $this->store->softDeleteSubtree(collect($ids));

            if ($freedBytes > 0) {
                $this->storageStore->adjustUsedBytes(storage: $storage, delta: -$freedBytes);
            }

            event(new NodeForceDeleted(
                storageId: $current->storage_id,
                nodeIds: array_values($ids),
                freedBytes: $freedBytes,
                actorId: $actorId,
            ));
        });
    }

    /**
     * Detach every blob edge of every version of a file node and return its live
     * head size (the bytes the storage stops accounting for). Detaching only drops
     * the reference — the physical object is reclaimed later by GC.
     */
    private function detachFilePayload(Node $node): int
    {
        /** @var File|null $file */
        $file = $node->file()->first();

        if (!$file instanceof File) {
            return 0;
        }

        foreach ($file->versions()->get() as $version) {
            $this->detachVersionEdges($version);
        }

        return $node->size_bytes;
    }

    private function detachVersionEdges(FileRevision $version): void
    {
        foreach ($version->blobs as $blob) {
            /** @var FileRevisionBlob $pivot */
            $pivot = $blob->getRelationValue('pivot');

            $this->blobs->detach(
                version: $version,
                blob: $blob,
                role: $pivot->role,
                ordinal: $pivot->ordinal,
            );
        }
    }
}

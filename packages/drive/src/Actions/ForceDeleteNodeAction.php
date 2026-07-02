<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions;

use Illuminate\Support\Facades\DB;
use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Drive\Events\NodeForceDeleted;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileVersion;
use Vaulter\Models\Drive\FileVersionBlob;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Repositories\Drive\StorageStoreRepository;

/**
 * Permanently soft-delete a node and its subtree (§2.10 stage 2). The order
 * matters and is the GC contract (§2.3 / §2.4):
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
        private StorageStoreRepository $storageStore,
        private BlobStore $blobs,
    ) {}

    public function execute(Node $node, int|string|null $actorId = null): void
    {

        $subtree = $this->nodes->subtree($node);
        $ids = $subtree->modelKeys();
        $storage = $node->storage;

        DB::transaction(function () use ($subtree, $ids, $node, $storage, $actorId): void {
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
                storageId: $node->storage_id,
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

    private function detachVersionEdges(FileVersion $version): void
    {
        foreach ($version->blobs as $blob) {
            /** @var FileVersionBlob $pivot */
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

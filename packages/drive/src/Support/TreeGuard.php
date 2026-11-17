<?php

declare(strict_types=1);

namespace Vaulter\Drive\Support;

use Illuminate\Support\Collection;
use LogicException;
use Packman\Attributes\Internal;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Exceptions\Nodes\InvalidDestinationException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Repositories\NodeReadRepository;
use Vaulter\Drive\Repositories\NodeStoreRepository;
use Vaulter\Support\Identity;

/**
 * The tree invariants every structural Action enforces: a parent is a live folder of
 * the same drive, and the root is never an operand. The checks read rows locked by
 * {@see lock()} inside the Action's transaction — checking the caller's possibly
 * stale model instead would let two concurrent operations both pass. The caller
 * picks the exception for a destination in another drive, since Drive and Documents
 * report it under different public codes.
 *
 * shared by the Drive and Documents Actions; not a public extension point
 */
#[Internal]
final readonly class TreeGuard
{
    public function __construct(
        private NodeReadRepository $nodes,
        private NodeStoreRepository $store,
    ) {}

    /**
     * The destination folder id; no destination means the drive root, so no Action
     * ever writes a second parentless node.
     */
    public function destinationId(Drive $storage, ?Node $into): int|string
    {
        if ($into instanceof Node) {
            return $into->id;
        }

        $rootId = $storage->root_node_id;

        if ($rootId === null) {
            throw new LogicException(
                "[Vaulter] Storage [{$storage->id}] has no root node. "
                .'Fix: provision it through StorageManager::root() before writing nodes.',
            );
        }

        return $rootId;
    }

    /**
     * @param  list<int|string|null>    $ids
     * @return Collection<string, Node>
     */
    public function lock(array $ids): Collection
    {
        return $this->store->lockForUpdate($ids);
    }

    /**
     * @param Collection<string, Node> $locked
     */
    public function locked(Collection $locked, int|string $id): Node
    {
        $node = $locked->get(Identity::stringify($id));

        if (!$node instanceof Node) {
            throw NodeNotFoundException::forId($id);
        }

        return $node;
    }

    /**
     * Bring the caller's instance up to the locked row, so the write diffs against
     * the current state (a stale attribute equal to the new value would otherwise not
     * be saved) and the caller keeps holding the instance it passed in.
     */
    public function sync(Node $node, Node $locked): Node
    {
        $node->setRawAttributes($locked->getAttributes(), sync: true);

        return $node;
    }

    /**
     * A locked node that a concurrent trash took since the caller looked: a writer that
     * holds the node lock must not write into the trash.
     */
    public function assertLive(Node $locked): void
    {
        if ($locked->trashed_at !== null) {
            throw NodeNotFoundException::forId($locked->id);
        }
    }

    public function sameStorage(Node $destination, int|string $storageId): bool
    {
        return Identity::stringify($destination->drive_id) === Identity::stringify($storageId);
    }

    public function assertActiveFolder(Node $destination): void
    {
        if ($destination->kind !== NodeKind::Folder) {
            throw InvalidDestinationException::notAFolder($destination);
        }

        if ($destination->trashed_at !== null) {
            throw InvalidDestinationException::trashed($destination);
        }
    }

    public function assertNotRoot(Node $node, string $operation): void
    {
        if ($this->nodes->isRoot($node)) {
            throw InvalidDestinationException::rootIsImmutable(root: $node, operation: $operation);
        }
    }
}

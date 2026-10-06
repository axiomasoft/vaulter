<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Nodes;

use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Data\Nodes\RenameNodeInput;
use Vaulter\Drive\Events\Nodes\NodeRenamed;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\NodeName;
use Vaulter\Support\TreeGuard;

/**
 * Rename a node in place (§2.2), subject to the sibling-name conflict check at
 * its current parent (the node itself is excluded from the check). The root is
 * never renamed; the check runs against the locked row.
 *
 * Authorization (M2a / H.2) is enforced upstream at the HTTP controller
 * boundary (NodeController + AccessResolver); this Action stays pure.
 */
final readonly class RenameNodeAction
{
    public function __construct(
        private NodeReadRepository $nodes,
        private NodeStoreRepository $store,
        private VaulterDatabase $database,
        private TreeGuard $tree,
        private DriveConfig $driveConfig,
    ) {}

    public function execute(RenameNodeInput $data): Node
    {
        $node = $data->node;
        $name = NodeName::normalize(name: $data->name, maxLength: $this->driveConfig->nameMaxLength());

        return $this->database->transaction(function () use ($node, $name, $data): Node {
            $this->database->lockTreeShared($node->drive_id);
            $current = $this->tree->sync($node, $this->tree->locked($this->tree->lock([$node->id]), $node->id));

            // The parent row is locked too, so a create into the same folder cannot claim
            // the new name between the sibling check and the UPDATE. Locking upward from
            // the node cannot cycle: the tree is acyclic and moves are excluded.
            $this->tree->lock([$current->parent_id]);
            $this->tree->assertNotRoot(node: $current, operation: 'rename');

            $fromName = $current->name;

            if ($fromName === $name) {
                return $current;
            }

            $exists = $this->nodes->siblingNameExists(
                storage: $current->storage,
                parentId: $current->parent_id,
                name: $name,
                ignoreId: $current->id,
            );

            if ($exists) {
                throw NodeNameConflictException::forName(name: $name, parentId: $current->parent_id);
            }

            $renamed = $this->store->rename(node: $current, name: $name);

            event(new NodeRenamed(
                node: $renamed,
                fromName: $fromName,
                toName: $name,
                actorId: $data->actorId,
            ));

            return $renamed;
        });
    }
}

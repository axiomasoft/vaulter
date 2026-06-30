<?php

declare(strict_types=1);

namespace Vaulter\Actions\Drive;

use Illuminate\Support\Facades\DB;
use Vaulter\Data\RenameNodeData;
use Vaulter\Events\NodeRenamed;
use Vaulter\Exceptions\NodeNameConflictException;
use Vaulter\Models\Node;
use Vaulter\Repositories\NodeReadRepository;
use Vaulter\Repositories\NodeStoreRepository;

/**
 * Rename a node in place (§2.2), subject to the sibling-name conflict check at
 * its current parent (the node itself is excluded from the check).
 *
 * Authorization (M2a / H.2) is enforced upstream at the HTTP controller
 * boundary (NodeController + AccessResolver); this Action stays pure.
 */
final readonly class RenameNodeAction
{
    public function __construct(
        private NodeReadRepository $nodes,
        private NodeStoreRepository $store,
    ) {}

    public function execute(RenameNodeData $data): Node
    {

        $node = $data->node;
        $fromName = $node->name;

        if ($fromName === $data->name) {
            return $node;
        }

        $exists = $this->nodes->siblingNameExists(
            storage: $node->storage,
            parentId: $node->parent_id,
            name: $data->name,
            ignoreId: $node->id,
        );

        if ($exists) {
            throw NodeNameConflictException::forName(name: $data->name, parentId: $node->parent_id);
        }

        return DB::transaction(function () use ($node, $fromName, $data): Node {
            $renamed = $this->store->rename(node: $node, name: $data->name);

            event(new NodeRenamed(
                node: $renamed,
                fromName: $fromName,
                toName: $data->name,
                actorId: $data->actorId,
            ));

            return $renamed;
        });
    }
}

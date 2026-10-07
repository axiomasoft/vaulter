<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Nodes;

use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Data\Nodes\UpdateNodeInput;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\NodeSequence;
use Vaulter\Operations\OperationContext;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\TreeGuard;

final readonly class UpdateNodeAction
{
    public function __construct(private VaulterDatabase $database, private TreeGuard $tree, private NodeStoreRepository $nodes, private NodeSequence $sequence, private NodeLocator $locator) {}

    public function execute(OperationContext $context, UpdateNodeInput $data): Node
    {
        $node = $this->locator->find($context, $data->node);

        return $this->database->transaction(function () use ($node, $data): Node {
            $this->database->lockTreeShared($node->drive_id);
            $current = $this->tree->locked($this->tree->lock([$node->id]), $node->id);
            $this->sequence->assertExpected($current, $data->expectedSequence);
            $attributes = [];
            if ($data->pinned !== null && $data->pinned !== $current->is_pinned) {
                $attributes['is_pinned'] = $data->pinned;
            }
            if ($data->position !== null && $data->position !== $current->position) {
                $attributes['position'] = $data->position;
            }
            if ($attributes !== []) {
                $this->nodes->update($current, $attributes);
                $this->sequence->bump($current);
            }

            return $current;
        });
    }
}

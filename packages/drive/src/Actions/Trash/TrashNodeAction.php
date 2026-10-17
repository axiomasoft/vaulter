<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Trash;

use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Data\Trash\TrashNodeInput;
use Vaulter\Drive\Events\Nodes\NodeTrashed;
use Vaulter\Drive\Exceptions\Nodes\InvalidDestinationException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Drive\Operations\NodeSequence;
use Vaulter\Drive\Repositories\NodeReadRepository;
use Vaulter\Drive\Repositories\NodeStoreRepository;
use Vaulter\Drive\Support\DriveLifecycle;
use Vaulter\Drive\Support\TreeGuard;
use Vaulter\Events\EventRecorder;

#[Internal]
final readonly class TrashNodeAction
{
    public function __construct(
        private NodeReadRepository $nodes,
        private NodeStoreRepository $store,
        private VaulterDatabase $database,
        private TreeGuard $tree,
        private NodeSequence $sequence,
        private DriveLifecycle $lifecycle,
        private EventRecorder $recorder,
    ) {}

    public function execute(DriveContext $context, TrashNodeInput $data): Node
    {
        $node = app(NodeLocator::class)->find(context: $context, key: $data->node, includeTrashed: true);

        return $this->database->transaction(function () use ($node, $data, $context): Node {
            $this->database->lockAggregate('drives', $context->driveId());
            $this->lifecycle->assertWritable($context);
            $locked = $this->tree->lock(array_values($this->nodes->subtree($node)->modelKeys()));
            $current = $this->tree->locked($locked, $node->id);
            $this->sequence->assertExpected(locked: $current, expected: $data->expectedSequence);
            $this->tree->assertNotRoot(node: $current, operation: 'trash');
            if ($current->trashed_at !== null) {
                throw InvalidDestinationException::trashed($current);
            }
            $changed = $locked->filter(fn (Node $member): bool => $member->trashed_at === null);
            foreach ($changed as $member) {
                if (!$this->tree->sameStorage($member, $context->driveId())) {
                    throw InvalidDestinationException::otherDrive($member, $context->driveId());
                }
            }
            $this->store->trashSubtree(ids: $changed->map(fn (Node $member) => $member->id)->values(),
                root: $current, actorId: $context->actor->ref()->id, at: now());
            foreach ($changed as $member) {
                $this->sequence->bump($member);
            }
            $fresh = $current->refresh();
            $this->recorder->record(new NodeTrashed(node: $fresh, actor: $context->actor->ref(), correlationId: $context->correlationId));

            return $fresh;
        });
    }
}

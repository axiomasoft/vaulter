<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Nodes;

use Carbon\CarbonImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Events\DriveEvent;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\ValueObjects\Events\ActivityTarget;
use Vaulter\Drive\ValueObjects\Events\JournalEffect;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A node was re-parented within its storage tree — a single parent_id UPDATE
 * . Carries the old parent so listeners can reconstruct the move.
 */
#[Api]
final readonly class NodeMoved extends DriveEvent
{
    public NodeSnapshot $node;

    private Node $nodeRow;

    #[Internal]
    public function __construct(
        NodeSnapshotMapper $nodeSnapshots,
        Node $node,
        public int|string|null $fromParentId,
        public int|string|null $toParentId,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        // The public facts are captured here, in the transaction that raised the event; the hooks keep the row.
        $this->nodeRow = $node;
        $this->node = $nodeSnapshots->facts($node);
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DriveEventType
    {
        return DriveEventType::NodeMoved;
    }

    public function scope(): EventScope
    {
        return self::nodeScope($this->nodeRow);
    }

    public function subject(): EventSubject
    {
        return self::nodeSubject($this->nodeRow);
    }

    public function sequence(): int
    {
        return $this->nodeRow->sequence;
    }

    public function journal(): JournalEffect
    {
        return self::nodeUpsert($this->nodeRow, subtree: true, sequence: $this->nodeRow->sequence);
    }

    public function data(): array
    {
        return [
            'from_parent_id' => self::id($this->fromParentId),
            'to_parent_id' => self::id($this->toParentId),
        ];
    }

    public function activityContext(): array
    {
        return [
            'from_parent_id' => Identity::stringify($this->fromParentId),
            'to_parent_id' => Identity::stringify($this->toParentId),
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->nodeRow);
    }
}

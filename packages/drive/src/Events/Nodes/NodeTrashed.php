<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Nodes;

use Carbon\CarbonImmutable;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\Changes\ChangeReason;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Events\DriveEvent;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\ValueObjects\Events\ActivityTarget;
use Vaulter\Drive\ValueObjects\Events\JournalEffect;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A node and its whole subtree were soft-trashed: the trashed
 * triplet is set atomically. used_bytes is NOT decremented — trashed bytes still
 * occupy storage until forceDelete.
 */
#[Api]
final readonly class NodeTrashed extends DriveEvent
{
    public NodeSnapshot $node;

    private Node $nodeRow;

    #[Internal]
    public function __construct(
        NodeSnapshotMapper $nodeSnapshots,
        Node $node,
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
        return DriveEventType::NodeTrashed;
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
        return JournalEffect::remove(nodeId: $this->nodeRow->getKey(), reason: ChangeReason::Deleted, nodeSequence: $this->nodeRow->sequence);
    }

    public function data(): array
    {
        return [];
    }

    public function activityContext(): array
    {
        return ['name' => $this->nodeRow->name];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->nodeRow);
    }
}

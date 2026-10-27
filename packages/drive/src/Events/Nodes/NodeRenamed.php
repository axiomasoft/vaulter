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
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A node was renamed in place. The names are recorded in the activity feed
 * only; a webhook receiver reads the node for its current name.
 */
#[Api]
final readonly class NodeRenamed extends DriveEvent
{
    public NodeSnapshot $node;

    private Node $nodeRow;

    #[Internal]
    public function __construct(
        Node $node,
        public string $fromName,
        public string $toName,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        // The public facts are captured here, in the transaction that raised the event; the hooks keep the row.
        $this->nodeRow = $node;
        $this->node = app(NodeSnapshotMapper::class)->facts($node);
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DriveEventType
    {
        return DriveEventType::NodeRenamed;
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
        return self::nodeUpsert($this->nodeRow, sequence: $this->nodeRow->sequence);
    }

    public function data(): array
    {
        return [];
    }

    public function activityContext(): array
    {
        return [
            'from' => $this->fromName,
            'to' => $this->toName,
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->nodeRow);
    }
}

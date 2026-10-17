<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Nodes;

use Carbon\CarbonImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\ValueObjects\Events\ActivityTarget;
use Vaulter\Drive\ValueObjects\Events\JournalEffect;
use Vaulter\Events\DomainEvent;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A node was re-parented within its storage tree — a single parent_id UPDATE
 * . Carries the old parent so listeners can reconstruct the move.
 */
#[Api]
final readonly class NodeMoved extends DomainEvent
{
    #[Internal]
    public function __construct(
        public Node $node,
        public int|string|null $fromParentId,
        public int|string|null $toParentId,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DriveEventType
    {
        return DriveEventType::NodeMoved;
    }

    public function scope(): EventScope
    {
        return self::nodeScope($this->node);
    }

    public function subject(): EventSubject
    {
        return self::nodeSubject($this->node);
    }

    public function sequence(): int
    {
        return $this->node->sequence;
    }

    public function journal(): JournalEffect
    {
        return self::nodeUpsert($this->node, subtree: true, sequence: $this->node->sequence);
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
        return ActivityTarget::of($this->node);
    }
}

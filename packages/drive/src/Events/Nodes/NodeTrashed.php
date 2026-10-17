<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Nodes;

use Carbon\CarbonImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Enums\Changes\ChangeReason;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\ValueObjects\Events\ActivityTarget;
use Vaulter\Drive\ValueObjects\Events\JournalEffect;
use Vaulter\Events\DomainEvent;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A node and its whole subtree were soft-trashed: the trashed
 * triplet is set atomically. used_bytes is NOT decremented — trashed bytes still
 * occupy storage until forceDelete.
 */
#[Api]
final readonly class NodeTrashed extends DomainEvent
{
    #[Internal]
    public function __construct(
        public Node $node,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DriveEventType
    {
        return DriveEventType::NodeTrashed;
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
        return JournalEffect::remove(nodeId: $this->node->getKey(), reason: ChangeReason::Deleted, nodeSequence: $this->node->sequence);
    }

    public function data(): array
    {
        return [];
    }

    public function activityContext(): array
    {
        return ['name' => $this->node->name];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->node);
    }
}

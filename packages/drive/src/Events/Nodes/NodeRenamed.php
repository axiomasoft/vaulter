<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Nodes;

use Carbon\CarbonImmutable;
use Vaulter\Enums\Events\EventType;
use Vaulter\Events\DomainEvent;
use Vaulter\Models\Drive\Node;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\ActivityTarget;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A node was renamed in place (§2.2). The names are recorded in the activity feed
 * only; a webhook receiver reads the node for its current name.
 */
final readonly class NodeRenamed extends DomainEvent
{
    public function __construct(
        public Node $node,
        public string $fromName,
        public string $toName,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): EventType
    {
        return EventType::NodeRenamed;
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
        return ActivityTarget::of($this->node);
    }
}

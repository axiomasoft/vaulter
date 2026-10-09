<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Nodes;

use Carbon\CarbonImmutable;
use Vaulter\Enums\Events\EventType;
use Vaulter\Events\DomainEvent;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\ActivityTarget;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A node was copied to a destination (§2.4). For files the copy references the
 * SAME blob through a new edge (dedup keeps one physical object); for folders the
 * whole subtree is deep-copied. $copy is the newly created destination root.
 */
final readonly class NodeCopied extends DomainEvent
{
    public function __construct(
        public Node $source,
        public Node $copy,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): EventType
    {
        return EventType::NodeCopied;
    }

    public function scope(): EventScope
    {
        return self::nodeScope($this->copy);
    }

    public function subject(): EventSubject
    {
        return self::nodeSubject($this->copy);
    }

    public function sequence(): int
    {
        return $this->copy->sequence;
    }

    public function data(): array
    {
        return [
            'source_id' => self::id($this->source->getKey()),
            'destination_id' => self::id($this->copy->parent_id),
        ];
    }

    public function activityContext(): array
    {
        return [
            'source_id' => Identity::stringify($this->source->getKey()),
            'name' => $this->copy->name,
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->copy);
    }
}

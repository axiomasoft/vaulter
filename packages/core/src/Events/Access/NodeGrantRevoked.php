<?php

declare(strict_types=1);

namespace Vaulter\Events\Access;

use Carbon\CarbonImmutable;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Enums\Events\EventType;
use Vaulter\Events\DomainEvent;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\ActivityTarget;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A subject's grants on a node were revoked (§2.6 / H.2). The grant rows are gone,
 * so the event carries the node and the subject (a value object) plus how many
 * rows were removed.
 */
final readonly class NodeGrantRevoked extends DomainEvent
{
    public function __construct(
        public Node $node,
        public Subject $subject,
        public int $revoked,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): EventType
    {
        return EventType::GrantRevoked;
    }

    public function scope(): EventScope
    {
        return self::nodeScope($this->node);
    }

    public function subject(): EventSubject
    {
        return self::nodeSubject($this->node);
    }

    public function data(): array
    {
        return [
            'subject' => ['type' => $this->subject->type, 'id' => self::id($this->subject->id)],
            'revoked' => $this->revoked,
        ];
    }

    public function activityContext(): array
    {
        return [
            'subject_type' => $this->subject->type,
            'subject_id' => Identity::stringify($this->subject->id),
            'revoked' => $this->revoked,
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->node);
    }
}

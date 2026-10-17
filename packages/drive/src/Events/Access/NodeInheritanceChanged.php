<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Access;

use Carbon\CarbonImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Models\Node;
use Vaulter\Enums\Events\DriveEventType;
use Vaulter\Events\DomainEvent;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\ActivityTarget;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;
use Vaulter\ValueObjects\Events\JournalEffect;

/**
 * A node started or stopped inheriting grants from above. Restricting with
 * copy_current also writes direct grants; each of those is its own grant.created.
 */
#[Api]
final readonly class NodeInheritanceChanged extends DomainEvent
{
    #[Internal]
    public function __construct(
        public Node $node,
        public bool $inherits,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DriveEventType
    {
        return DriveEventType::InheritanceChanged;
    }

    public function scope(): EventScope
    {
        return self::nodeScope($this->node);
    }

    public function subject(): EventSubject
    {
        return self::nodeSubject($this->node);
    }

    public function journal(): JournalEffect
    {
        return self::nodeUpsert($this->node, subtree: true);
    }

    public function data(): array
    {
        return ['inherits' => $this->inherits];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->node);
    }
}

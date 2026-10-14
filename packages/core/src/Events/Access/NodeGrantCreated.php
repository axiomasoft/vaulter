<?php

declare(strict_types=1);

namespace Vaulter\Events\Access;

use Carbon\CarbonImmutable;
use Vaulter\Enums\Events\DriveEventType;
use Vaulter\Events\DomainEvent;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\ActivityTarget;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;
use Vaulter\ValueObjects\Events\JournalEffect;

/**
 * A per-node ACL grant was created or updated. Host listeners receive it
 * after the grant transaction commits.
 */
final readonly class NodeGrantCreated extends DomainEvent
{
    public function __construct(
        public NodeGrant $grant,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DriveEventType
    {
        return DriveEventType::GrantCreated;
    }

    public function scope(): EventScope
    {
        return self::nodeScope($this->grant->node);
    }

    public function subject(): EventSubject
    {
        return self::nodeSubject($this->grant->node);
    }

    public function journal(): JournalEffect
    {
        return self::nodeUpsert($this->grant->node, subtree: true);
    }

    public function data(): array
    {
        return [
            'grant_id' => self::id($this->grant->getKey()),
            'subject' => ['type' => $this->grant->subject_type, 'id' => self::id($this->grant->subject_id)],
            'role' => $this->grant->role_key,
            'role_version' => $this->grant->role_version,
            'scope' => $this->grant->scope->value,
        ];
    }

    public function activityContext(): array
    {
        return [
            'grant_id' => Identity::stringify($this->grant->getKey()),
            'subject_type' => $this->grant->subject_type,
            'subject_id' => Identity::stringify($this->grant->subject_id),
            'role' => $this->grant->role_key,
            'role_version' => $this->grant->role_version,
            'scope' => $this->grant->scope->value,
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->grant->node);
    }
}

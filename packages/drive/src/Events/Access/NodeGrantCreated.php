<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Access;

use Carbon\CarbonImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\ValueObjects\Events\ActivityTarget;
use Vaulter\Drive\ValueObjects\Events\JournalEffect;
use Vaulter\Events\DomainEvent;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A per-node ACL grant was created or updated. Host listeners receive it
 * after the grant transaction commits.
 */
#[Api]
final readonly class NodeGrantCreated extends DomainEvent
{
    #[Internal]
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

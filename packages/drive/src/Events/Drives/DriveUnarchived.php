<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Drives;

use Carbon\CarbonImmutable;
use Vaulter\Enums\Events\EventType;
use Vaulter\Events\DomainEvent;
use Vaulter\Models\Drive\Drive;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\ActivityTarget;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/** The drive changed its lifecycle status (PLAN2.D27): it is now active again. */
final readonly class DriveUnarchived extends DomainEvent
{
    public function __construct(
        public Drive $drive,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): EventType
    {
        return EventType::DriveUnarchived;
    }

    public function scope(): EventScope
    {
        return new EventScope(tenantId: $this->drive->tenant_id, driveId: $this->drive->getKey(), profile: $this->drive->profile);
    }

    public function subject(): EventSubject
    {
        return new EventSubject(type: 'drive', id: $this->drive->getKey());
    }

    public function data(): array
    {
        return [];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->drive);
    }
}

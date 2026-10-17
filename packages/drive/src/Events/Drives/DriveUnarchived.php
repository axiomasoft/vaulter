<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Drives;

use Carbon\CarbonImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\ValueObjects\Events\ActivityTarget;
use Vaulter\Events\DomainEvent;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/** The drive changed its lifecycle status: it is now active again. */
#[Api]
final readonly class DriveUnarchived extends DomainEvent
{
    #[Internal]
    public function __construct(
        public Drive $drive,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DriveEventType
    {
        return DriveEventType::DriveUnarchived;
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

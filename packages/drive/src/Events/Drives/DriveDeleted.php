<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Drives;

use Carbon\CarbonImmutable;
use Vaulter\Enums\Events\EventType;
use Vaulter\Events\DomainEvent;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * The last row of a drive is gone: its purge finished. The row no longer
 * exists, so the event carries its ids, tenant and profile.
 */
final readonly class DriveDeleted extends DomainEvent
{
    public function __construct(
        public string $driveId,
        public string $ownerType,
        public string $ownerId,
        public int|string|null $tenantId,
        public string $profile,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): EventType
    {
        return EventType::DriveDeleted;
    }

    public function scope(): EventScope
    {
        return new EventScope(tenantId: $this->tenantId, driveId: $this->driveId, profile: $this->profile);
    }

    public function subject(): EventSubject
    {
        return new EventSubject(type: 'drive', id: $this->driveId);
    }

    public function data(): array
    {
        return [];
    }

    public function activityContext(): array
    {
        return [
            'owner_type' => $this->ownerType,
            'owner_id' => $this->ownerId,
        ];
    }
}

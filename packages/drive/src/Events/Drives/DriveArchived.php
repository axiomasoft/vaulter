<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Drives;

use Carbon\CarbonImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Data\Snapshots\DriveSnapshot;
use Vaulter\Drive\Data\Snapshots\Mapping\DriveSnapshotMapper;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Events\DriveEvent;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\ValueObjects\Events\ActivityTarget;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/** The drive changed its lifecycle status: it is now archived. */
#[Api]
final readonly class DriveArchived extends DriveEvent
{
    public DriveSnapshot $drive;

    private Drive $driveRow;

    #[Internal]
    public function __construct(
        Drive $drive,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        // The public facts are captured here, in the transaction that raised the event; the hooks keep the row.
        $this->driveRow = $drive;
        $this->drive = app(DriveSnapshotMapper::class)->map($drive);
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DriveEventType
    {
        return DriveEventType::DriveArchived;
    }

    public function scope(): EventScope
    {
        return new EventScope(tenantId: $this->driveRow->tenant_id, driveId: $this->driveRow->getKey(), profile: $this->driveRow->profile);
    }

    public function subject(): EventSubject
    {
        return new EventSubject(type: 'drive', id: $this->driveRow->getKey());
    }

    public function data(): array
    {
        return [];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->driveRow);
    }
}

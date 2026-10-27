<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Uploads;

use Carbon\CarbonImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Data\Snapshots\UploadSessionSnapshot;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Events\DriveEvent;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A stale resumable-upload session was reclaimed by the sweeper
 * after its staged temp and protocol-side state were released. The sweeper is a
 * system actor.
 */
#[Api]
final readonly class UploadExpired extends DriveEvent
{
    public UploadSessionSnapshot $session;

    private UploadSession $sessionRow;

    #[Internal]
    public function __construct(
        UploadSession $session,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        // The public facts are captured here, in the transaction that raised the event; the hooks keep the row.
        $this->sessionRow = $session;
        $this->session = UploadSessionSnapshot::fromModel($session);
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DriveEventType
    {
        return DriveEventType::UploadExpired;
    }

    public function scope(): EventScope
    {
        return new EventScope(tenantId: $this->sessionRow->tenant_id, driveId: $this->sessionRow->drive_id, profile: $this->sessionRow->storage->profile ?? 'default');
    }

    public function subject(): EventSubject
    {
        return new EventSubject(type: 'upload', id: $this->sessionRow->upload_id);
    }

    public function data(): array
    {
        return [
            'upload_id' => $this->sessionRow->upload_id,
            'size' => $this->sessionRow->total_size_bytes,
        ];
    }

    public function activityContext(): array
    {
        return [
            'upload_id' => $this->sessionRow->upload_id,
            'protocol' => $this->sessionRow->protocol->value,
            'received_bytes' => $this->sessionRow->received_bytes,
        ];
    }
}

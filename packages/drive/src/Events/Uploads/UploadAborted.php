<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Uploads;

use Carbon\CarbonImmutable;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Vaulter\Drive\Data\Snapshots\UploadSessionSnapshot;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Events\DriveEvent;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A resumable-upload session was aborted: the staged temp and any
 * protocol-side state (S3 multipart) were already reclaimed by
 * {@see UploadSessionManager::abort()} in the same transaction.
 */
#[Api]
final readonly class UploadAborted extends DriveEvent
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
        return DriveEventType::UploadAborted;
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

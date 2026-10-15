<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Uploads;

use Carbon\CarbonImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Enums\Events\DriveEventType;
use Vaulter\Events\DomainEvent;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A resumable-upload session was opened. Audited only: upload
 * sessions are not a webhook type.
 */
#[Api]
final readonly class UploadInitiated extends DomainEvent
{
    #[Internal]
    public function __construct(
        public UploadSession $session,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DriveEventType
    {
        return DriveEventType::UploadInitiated;
    }

    public function scope(): EventScope
    {
        return new EventScope(tenantId: $this->session->tenant_id, driveId: $this->session->drive_id, profile: $this->session->storage->profile ?? 'default');
    }

    public function subject(): EventSubject
    {
        return new EventSubject(type: 'upload', id: $this->session->upload_id);
    }

    public function data(): array
    {
        return [
            'upload_id' => $this->session->upload_id,
            'size' => $this->session->total_size_bytes,
        ];
    }

    public function activityContext(): array
    {
        return [
            'upload_id' => $this->session->upload_id,
            'protocol' => $this->session->protocol->value,
            'target_name' => $this->session->target_name,
        ];
    }
}

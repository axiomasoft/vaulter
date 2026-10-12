<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Uploads;

use Carbon\CarbonImmutable;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Enums\Events\EventType;
use Vaulter\Events\DomainEvent;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A stale resumable-upload session was reclaimed by the sweeper
 * after its staged temp and protocol-side state were released. The sweeper is a
 * system actor.
 */
final readonly class UploadExpired extends DomainEvent
{
    public function __construct(
        public UploadSession $session,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): EventType
    {
        return EventType::UploadExpired;
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
            'received_bytes' => $this->session->received_bytes,
        ];
    }
}

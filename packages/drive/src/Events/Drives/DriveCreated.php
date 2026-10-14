<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Drives;

use Carbon\CarbonImmutable;
use Vaulter\Enums\Events\DriveEventType;
use Vaulter\Events\DomainEvent;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\ActivityTarget;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A storage container was provisioned for a host entity, together with its
 * auto-created root folder node.
 */
final readonly class DriveCreated extends DomainEvent
{
    public function __construct(
        public Drive $storage,
        public Node $root,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DriveEventType
    {
        return DriveEventType::DriveCreated;
    }

    public function scope(): EventScope
    {
        return new EventScope(tenantId: $this->storage->tenant_id, driveId: $this->storage->getKey(), profile: $this->storage->profile);
    }

    public function subject(): EventSubject
    {
        return new EventSubject(type: 'drive', id: $this->storage->getKey());
    }

    public function data(): array
    {
        return [
            'root_node_id' => self::id($this->root->getKey()),
            'profile' => $this->storage->profile,
        ];
    }

    public function activityContext(): array
    {
        return [
            'drive_id' => Identity::stringify($this->storage->getKey()),
            'owner_type' => $this->storage->owner_type,
            'owner_id' => Identity::stringify($this->storage->owner_id),
            'name' => $this->storage->name,
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->root);
    }
}

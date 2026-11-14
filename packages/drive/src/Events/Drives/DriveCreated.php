<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Drives;

use Carbon\CarbonImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Data\Snapshots\DriveSnapshot;
use Vaulter\Drive\Data\Snapshots\Mapping\DriveSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Events\DriveEvent;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\ValueObjects\Events\ActivityTarget;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A storage container was provisioned for a host entity, together with its
 * auto-created root folder node.
 */
#[Api]
final readonly class DriveCreated extends DriveEvent
{
    public DriveSnapshot $storage;

    private Drive $storageRow;

    public NodeSnapshot $root;

    private Node $rootRow;

    #[Internal]
    public function __construct(
        DriveSnapshotMapper $driveSnapshots,
        NodeSnapshotMapper $nodeSnapshots,
        Drive $storage,
        Node $root,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        // The public facts are captured here, in the transaction that raised the event; the hooks keep the row.
        $this->storageRow = $storage;
        $this->storage = $driveSnapshots->map($storage);
        $this->rootRow = $root;
        $this->root = $nodeSnapshots->facts($root);
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DriveEventType
    {
        return DriveEventType::DriveCreated;
    }

    public function scope(): EventScope
    {
        return new EventScope(tenantId: $this->storageRow->tenant_id, driveId: $this->storageRow->getKey(), profile: $this->storageRow->profile);
    }

    public function subject(): EventSubject
    {
        return new EventSubject(type: 'drive', id: $this->storageRow->getKey());
    }

    public function data(): array
    {
        return [
            'root_node_id' => self::id($this->rootRow->getKey()),
            'profile' => $this->storageRow->profile,
        ];
    }

    public function activityContext(): array
    {
        return [
            'drive_id' => Identity::stringify($this->storageRow->getKey()),
            'owner_type' => $this->storageRow->owner_type,
            'owner_id' => Identity::stringify($this->storageRow->owner_id),
            'name' => $this->storageRow->name,
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->rootRow);
    }
}

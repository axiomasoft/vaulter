<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Nodes;

use Carbon\CarbonImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Events\DriveEvent;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A node and its subtree were permanently deleted (purge is a real DELETE), their
 * version↔blob edges detached so the blobs become GC-eligible (GC reclaims
 * the bytes — never deleted directly here). used_bytes was
 * decremented by the freed size. The node objects are gone, so the ids, the
 * reclaimed byte total and the drive's tenant and profile travel with the event.
 *
 * @property list<int|string> $nodeIds
 */
#[Api]
final readonly class NodePurged extends DriveEvent
{
    public const int LISTED_NODE_IDS = 1000;

    /** @param list<int|string> $nodeIds */
    public function __construct(
        public int|string $storageId,
        public array $nodeIds,
        public int $freedBytes,
        public int|string|null $tenantId,
        public string $profile,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DriveEventType
    {
        return DriveEventType::NodePurged;
    }

    public function scope(): EventScope
    {
        return new EventScope(tenantId: $this->tenantId, driveId: $this->storageId, profile: $this->profile);
    }

    public function subject(): EventSubject
    {
        return new EventSubject(type: 'drive', id: $this->storageId);
    }

    public function data(): array
    {
        return [
            // A huge purge reports how many nodes went, not which.
            ...(count($this->nodeIds) <= self::LISTED_NODE_IDS ? ['node_ids' => array_map(Identity::stringify(...), $this->nodeIds)] : []),
            'node_count' => count($this->nodeIds),
            'freed_bytes' => $this->freedBytes,
        ];
    }

    public function activityContext(): array
    {
        return [
            'node_ids' => array_map(Identity::stringify(...), $this->nodeIds),
            'freed_bytes' => $this->freedBytes,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Nodes;

use Carbon\CarbonImmutable;
use Vaulter\Enums\Events\EventType;
use Vaulter\Events\DomainEvent;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A node and its subtree were permanently deleted (purge is a real DELETE), their
 * version↔blob edges detached so the blobs become GC-eligible (M1b GC reclaims
 * the bytes — never deleted directly here, §2.4 / §2.3). used_bytes was
 * decremented by the freed size. The node objects are gone, so the ids, the
 * reclaimed byte total and the drive's tenant and profile travel with the event.
 *
 * @property list<int|string> $nodeIds
 */
final readonly class NodePurged extends DomainEvent
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

    public function type(): EventType
    {
        return EventType::NodePurged;
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

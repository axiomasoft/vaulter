<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Nodes;

use Carbon\CarbonImmutable;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Events\DriveEvent;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\ValueObjects\Events\ActivityTarget;
use Vaulter\Drive\ValueObjects\Events\JournalEffect;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A folder was created in a storage tree.
 */
#[Api]
final readonly class FolderCreated extends DriveEvent
{
    public NodeSnapshot $node;

    private Node $nodeRow;

    #[Internal]
    public function __construct(
        NodeSnapshotMapper $nodeSnapshots,
        Node $node,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        // The public facts are captured here, in the transaction that raised the event; the hooks keep the row.
        $this->nodeRow = $node;
        $this->node = $nodeSnapshots->facts($node);
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DriveEventType
    {
        return DriveEventType::FolderCreated;
    }

    public function scope(): EventScope
    {
        return self::nodeScope($this->nodeRow);
    }

    public function subject(): EventSubject
    {
        return self::nodeSubject($this->nodeRow);
    }

    public function sequence(): int
    {
        return $this->nodeRow->sequence;
    }

    public function journal(): JournalEffect
    {
        return self::nodeUpsert($this->nodeRow, sequence: $this->nodeRow->sequence);
    }

    public function data(): array
    {
        return ['parent_id' => self::id($this->nodeRow->parent_id)];
    }

    public function activityContext(): array
    {
        return [
            'kind' => $this->nodeRow->kind->value,
            'name' => $this->nodeRow->name,
            'parent_id' => Identity::stringify($this->nodeRow->parent_id),
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->nodeRow);
    }
}

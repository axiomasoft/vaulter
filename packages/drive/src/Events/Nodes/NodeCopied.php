<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Nodes;

use Carbon\CarbonImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
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
 * A node was copied to a destination. For files the copy references the
 * SAME blob through a new edge (dedup keeps one physical object); for folders the
 * whole subtree is deep-copied. $copy is the newly created destination root.
 */
#[Api]
final readonly class NodeCopied extends DriveEvent
{
    public NodeSnapshot $source;

    private Node $sourceRow;

    public NodeSnapshot $copy;

    private Node $copyRow;

    #[Internal]
    public function __construct(
        Node $source,
        Node $copy,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        // The public facts are captured here, in the transaction that raised the event; the hooks keep the row.
        $this->sourceRow = $source;
        $this->source = app(NodeSnapshotMapper::class)->facts($source);
        $this->copyRow = $copy;
        $this->copy = app(NodeSnapshotMapper::class)->facts($copy);
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DriveEventType
    {
        return DriveEventType::NodeCopied;
    }

    public function scope(): EventScope
    {
        return self::nodeScope($this->copyRow);
    }

    public function subject(): EventSubject
    {
        return self::nodeSubject($this->copyRow);
    }

    public function sequence(): int
    {
        return $this->copyRow->sequence;
    }

    public function journal(): JournalEffect
    {
        return self::nodeUpsert($this->copyRow, subtree: true, sequence: $this->copyRow->sequence);
    }

    public function data(): array
    {
        return [
            'source_id' => self::id($this->sourceRow->getKey()),
            'destination_id' => self::id($this->copyRow->parent_id),
        ];
    }

    public function activityContext(): array
    {
        return [
            'source_id' => Identity::stringify($this->sourceRow->getKey()),
            'name' => $this->copyRow->name,
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->copyRow);
    }
}

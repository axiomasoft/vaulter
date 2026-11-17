<?php

declare(strict_types=1);

namespace Vaulter\Documents\Events\Documents;

use Carbon\CarbonImmutable;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Vaulter\Documents\Data\Snapshots\DocumentRevisionSnapshot;
use Vaulter\Documents\Data\Snapshots\Mapping\DocumentSnapshotMapper;
use Vaulter\Documents\Enums\DocumentEventType;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Events\DriveEvent;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\ValueObjects\Events\ActivityTarget;
use Vaulter\Drive\ValueObjects\Events\JournalEffect;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A kind=document node was created with its initial v1 version. Carries
 * both the node and the v1 version so a listener need not re-read either, and the
 * content validators the write produced (state_version, collab_epoch).
 */
#[Api]
final readonly class DocumentCreated extends DriveEvent
{
    public NodeSnapshot $node;

    private Node $nodeRow;

    public DocumentRevisionSnapshot $version;

    private DocumentRevision $versionRow;

    #[Internal]
    public function __construct(
        Node $node,
        DocumentRevision $version,
        public int $stateVersion,
        public int $epoch,
        ActorRef $actor,
        string $correlationId,
        NodeSnapshotMapper $nodes,
        DocumentSnapshotMapper $documents,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        // The public facts are captured here, in the transaction that raised the event; the hooks keep the row.
        $this->nodeRow = $node;
        $this->node = $nodes->facts($node);
        $this->versionRow = $version;
        $this->version = $documents->revision($version, $version->document()->firstOrFail());
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DocumentEventType
    {
        return DocumentEventType::DocumentCreated;
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
        return [
            'type' => $this->versionRow->document->type,
            'revision_id' => self::id($this->versionRow->getKey()),
            'state_version' => $this->stateVersion,
            'epoch' => $this->epoch,
        ];
    }

    public function activityContext(): array
    {
        return [
            'name' => $this->nodeRow->name,
            'parent_id' => Identity::stringify($this->nodeRow->parent_id),
            'type' => $this->versionRow->document->type,
            'revision_id' => Identity::stringify($this->versionRow->getKey()),
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->nodeRow);
    }
}

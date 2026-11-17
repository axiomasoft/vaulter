<?php

declare(strict_types=1);

namespace Vaulter\Documents\Events\Revisions;

use Carbon\CarbonImmutable;
use LogicException;
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
 * A document was restored to an earlier checkpoint. Its working state is now a copy
 * of $restoredFrom, recorded as the new checkpoint $version, and the collaboration
 * epoch moved on: rooms opened before the restore are refused on their next store and
 * their clients must reopen the document. $epoch and $stateVersion are the values
 * after the restore.
 */
#[Api]
final readonly class DocumentRestored extends DriveEvent
{
    public NodeSnapshot $node;

    private Node $nodeRow;

    public DocumentRevisionSnapshot $version;

    private DocumentRevision $versionRow;

    public DocumentRevisionSnapshot $restoredFrom;

    private DocumentRevision $restoredFromRow;

    #[Internal]
    public function __construct(
        Node $node,
        DocumentRevision $version,
        DocumentRevision $restoredFrom,
        public int $epoch,
        public int $stateVersion,
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
        $this->restoredFromRow = $restoredFrom;
        $this->restoredFrom = $documents->revision($restoredFrom, $restoredFrom->document()->firstOrFail());
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    /**
     * Key of the document this write belongs to: the owner of {@see $version}, as the key column carries it.
     */
    public function documentId(): int|string
    {
        return Identity::key($this->versionRow->document_id)
            ?? throw new LogicException('A document event carries a revision without a document key.');
    }

    public function type(): DocumentEventType
    {
        return DocumentEventType::DocumentRestored;
    }

    public function scope(): EventScope
    {
        return self::nodeScope($this->nodeRow);
    }

    public function subject(): EventSubject
    {
        return self::nodeSubject($this->nodeRow);
    }

    public function journal(): JournalEffect
    {
        return self::nodeUpsert($this->nodeRow);
    }

    public function data(): array
    {
        return [
            'from_revision_id' => self::id($this->restoredFromRow->getKey()),
            'revision_id' => self::id($this->versionRow->getKey()),
            'state_version' => $this->stateVersion,
            'epoch' => $this->epoch,
        ];
    }

    public function activityContext(): array
    {
        return [
            'from_revision_id' => Identity::stringify($this->restoredFromRow->getKey()),
            'from_number' => $this->restoredFromRow->number,
            'revision_id' => Identity::stringify($this->versionRow->getKey()),
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->nodeRow);
    }
}

<?php

declare(strict_types=1);

namespace Vaulter\Documents\Events\Revisions;

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
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A NAMED document version was snapshotted, or a prior version restored to head
 * . $restored distinguishes the two: false for a fresh named snapshot,
 * true when a restore repointed the head to a copy of a prior version. $stateVersion and
 * $epoch are the document's content validators at that moment; a named snapshot moves
 * neither.
 */
#[Api]
final readonly class DocumentRevisionCreated extends DriveEvent
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
        public bool $restored,
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
        return DocumentEventType::DocumentRevisionCreated;
    }

    public function scope(): EventScope
    {
        return self::nodeScope($this->nodeRow);
    }

    public function subject(): EventSubject
    {
        return self::nodeSubject($this->nodeRow);
    }

    public function data(): array
    {
        return [
            'revision_id' => self::id($this->versionRow->getKey()),
            'state_version' => $this->stateVersion,
            'epoch' => $this->epoch,
            'restored' => $this->restored,
        ];
    }

    public function activityContext(): array
    {
        return [
            'revision_id' => Identity::stringify($this->versionRow->getKey()),
            'number' => $this->versionRow->number,
            'label' => $this->versionRow->label,
            'keep_forever' => $this->versionRow->keep_forever,
            'restored' => $this->restored,
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->nodeRow);
    }
}

<?php

declare(strict_types=1);

namespace Vaulter\Documents\Events\Documents;

use Carbon\CarbonImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
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
 * A document node's content was written — the autosave path. $created
 * is true when this write took a new checkpoint; false when the head checkpoint stayed
 * as it was (the checkpoint interval had not passed), so $version then predates the
 * write. $stateVersion and $epoch are the content validators after this write, taken
 * from the writer's result — use them, not $version, for the state of the document.
 *
 * Every save would flood the activity feed, so it is not audited; webhooks receive
 * the saves that took a checkpoint.
 */
#[Api]
final readonly class DocumentContentUpdated extends DriveEvent
{
    public NodeSnapshot $node;

    private Node $nodeRow;

    public DocumentRevisionSnapshot $version;

    private DocumentRevision $versionRow;

    #[Internal]
    public function __construct(
        Node $node,
        DocumentRevision $version,
        public bool $created,
        public int $stateVersion,
        public int $epoch,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        // The public facts are captured here, in the transaction that raised the event; the hooks keep the row.
        $this->nodeRow = $node;
        $this->node = app(NodeSnapshotMapper::class)->facts($node);
        $this->versionRow = $version;
        $this->version = app(DocumentSnapshotMapper::class)->revision($version, $version->document()->firstOrFail());
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    /**
     * Key of the document this write belongs to: the owner of {@see $version}, as the key column carries it.
     */
    #[Api]
    public function documentId(): int|string
    {
        return Identity::key($this->versionRow->document_id)
            ?? throw new \LogicException('A document event carries a revision without a document key.');
    }

    public function type(): DocumentEventType
    {
        return DocumentEventType::DocumentUpdated;
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
            ...($this->created ? ['revision_id' => self::id($this->versionRow->getKey())] : []),
            'checkpoint' => $this->created,
            'state_version' => $this->stateVersion,
            'epoch' => $this->epoch,
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->nodeRow);
    }

    #[\Override]
    public function isExternal(): bool
    {
        return $this->created;
    }
}

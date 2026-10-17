<?php

declare(strict_types=1);

namespace Vaulter\Documents\Events\Revisions;

use Carbon\CarbonImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Documents\Enums\DocumentEventType;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Drive\Models\Node;
use Vaulter\Events\DomainEvent;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\ActivityTarget;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;
use Vaulter\ValueObjects\Events\JournalEffect;

/**
 * A document was restored to an earlier checkpoint. Its working state is now a copy
 * of $restoredFrom, recorded as the new checkpoint $version, and the collaboration
 * epoch moved on: rooms opened before the restore are refused on their next store and
 * their clients must reopen the document. $epoch and $stateVersion are the values
 * after the restore.
 */
#[Api]
final readonly class DocumentRestored extends DomainEvent
{
    #[Internal]
    public function __construct(
        public Node $node,
        public DocumentRevision $version,
        public DocumentRevision $restoredFrom,
        public int $epoch,
        public int $stateVersion,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DocumentEventType
    {
        return DocumentEventType::DocumentRestored;
    }

    public function scope(): EventScope
    {
        return self::nodeScope($this->node);
    }

    public function subject(): EventSubject
    {
        return self::nodeSubject($this->node);
    }

    public function journal(): JournalEffect
    {
        return self::nodeUpsert($this->node);
    }

    public function data(): array
    {
        return [
            'from_revision_id' => self::id($this->restoredFrom->getKey()),
            'revision_id' => self::id($this->version->getKey()),
            'state_version' => $this->stateVersion,
            'epoch' => $this->epoch,
        ];
    }

    public function activityContext(): array
    {
        return [
            'from_revision_id' => Identity::stringify($this->restoredFrom->getKey()),
            'from_number' => $this->restoredFrom->number,
            'revision_id' => Identity::stringify($this->version->getKey()),
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->node);
    }
}

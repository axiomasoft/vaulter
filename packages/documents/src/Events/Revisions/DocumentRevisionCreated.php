<?php

declare(strict_types=1);

namespace Vaulter\Documents\Events\Revisions;

use Carbon\CarbonImmutable;
use Vaulter\Documents\Enums\DocumentEventType;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Events\DomainEvent;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\ActivityTarget;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A NAMED document version was snapshotted, or a prior version restored to head
 * . $restored distinguishes the two: false for a fresh named snapshot,
 * true when a restore repointed the head to a copy of a prior version. $stateVersion and
 * $epoch are the document's content validators at that moment; a named snapshot moves
 * neither.
 */
final readonly class DocumentRevisionCreated extends DomainEvent
{
    public function __construct(
        public Node $node,
        public DocumentRevision $version,
        public int $stateVersion,
        public int $epoch,
        public bool $restored,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DocumentEventType
    {
        return DocumentEventType::DocumentRevisionCreated;
    }

    public function scope(): EventScope
    {
        return self::nodeScope($this->node);
    }

    public function subject(): EventSubject
    {
        return self::nodeSubject($this->node);
    }

    public function data(): array
    {
        return [
            'revision_id' => self::id($this->version->getKey()),
            'state_version' => $this->stateVersion,
            'epoch' => $this->epoch,
            'restored' => $this->restored,
        ];
    }

    public function activityContext(): array
    {
        return [
            'revision_id' => Identity::stringify($this->version->getKey()),
            'number' => $this->version->number,
            'label' => $this->version->label,
            'keep_forever' => $this->version->keep_forever,
            'restored' => $this->restored,
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->node);
    }
}

<?php

declare(strict_types=1);

namespace Vaulter\Documents\Events\Documents;

use Carbon\CarbonImmutable;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Enums\Events\EventType;
use Vaulter\Events\DomainEvent;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\ActivityTarget;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;
use Vaulter\ValueObjects\Events\JournalEffect;

/**
 * A kind=document node was created with its initial v1 version (§2.5 / M4a). Carries
 * both the node and the v1 version so a listener need not re-read either, and the
 * content validators the write produced (state_version, collab_epoch).
 */
final readonly class DocumentCreated extends DomainEvent
{
    public function __construct(
        public Node $node,
        public DocumentRevision $version,
        public int $stateVersion,
        public int $epoch,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): EventType
    {
        return EventType::DocumentCreated;
    }

    public function scope(): EventScope
    {
        return self::nodeScope($this->node);
    }

    public function subject(): EventSubject
    {
        return self::nodeSubject($this->node);
    }

    public function sequence(): int
    {
        return $this->node->sequence;
    }

    public function journal(): JournalEffect
    {
        return self::nodeUpsert($this->node, sequence: $this->node->sequence);
    }

    public function data(): array
    {
        return [
            'type' => $this->version->document->type,
            'revision_id' => self::id($this->version->getKey()),
            'state_version' => $this->stateVersion,
            'epoch' => $this->epoch,
        ];
    }

    public function activityContext(): array
    {
        return [
            'name' => $this->node->name,
            'parent_id' => Identity::stringify($this->node->parent_id),
            'type' => $this->version->document->type,
            'revision_id' => Identity::stringify($this->version->getKey()),
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->node);
    }
}

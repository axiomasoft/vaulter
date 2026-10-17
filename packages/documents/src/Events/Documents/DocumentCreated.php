<?php

declare(strict_types=1);

namespace Vaulter\Documents\Events\Documents;

use Carbon\CarbonImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Documents\Enums\DocumentEventType;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
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
    #[Internal]
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

    public function type(): DocumentEventType
    {
        return DocumentEventType::DocumentCreated;
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

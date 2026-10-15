<?php

declare(strict_types=1);

namespace Vaulter\Documents\Events\Documents;

use Carbon\CarbonImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Documents\Enums\DocumentEventType;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Events\DomainEvent;
use Vaulter\Models\Drive\Node;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\ActivityTarget;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;
use Vaulter\ValueObjects\Events\JournalEffect;

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
final readonly class DocumentContentUpdated extends DomainEvent
{
    #[Internal]
    public function __construct(
        public Node $node,
        public DocumentRevision $version,
        public bool $created,
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
        return DocumentEventType::DocumentUpdated;
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
            ...($this->created ? ['revision_id' => self::id($this->version->getKey())] : []),
            'checkpoint' => $this->created,
            'state_version' => $this->stateVersion,
            'epoch' => $this->epoch,
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->node);
    }

    #[\Override]
    public function isExternal(): bool
    {
        return $this->created;
    }
}

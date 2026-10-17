<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Files;

use Carbon\CarbonImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Models\File;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\ValueObjects\Events\ActivityTarget;
use Vaulter\Events\DomainEvent;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A non-current revision was deleted, by the API or by the retention pruning
 * its blob edges are gone and its bytes left the drive's quota. The
 * revision row no longer exists, so the event carries its id, number and size.
 */
#[Api]
final readonly class FileRevisionDeleted extends DomainEvent
{
    #[Internal]
    public function __construct(
        public Node $node,
        public File $file,
        public int|string $revisionId,
        public int $revisionNo,
        public int $sizeBytes,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DriveEventType
    {
        return DriveEventType::FileRevisionDeleted;
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

    public function data(): array
    {
        return [
            'revision_id' => self::id($this->revisionId),
            'number' => $this->revisionNo,
        ];
    }

    public function activityContext(): array
    {
        return [
            'revision_id' => Identity::stringify($this->revisionId),
            'number' => $this->revisionNo,
            'size_bytes' => $this->sizeBytes,
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->node);
    }
}

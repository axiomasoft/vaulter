<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Files;

use Carbon\CarbonImmutable;
use Vaulter\Enums\Events\EventType;
use Vaulter\Events\DomainEvent;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\ActivityTarget;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;
use Vaulter\ValueObjects\Events\JournalEffect;

/**
 * A brand-new file node was uploaded — first version committed against a
 * (possibly deduplicated) blob (§2.4). A subsequent upload onto the same node
 * fires FileRevisionAdded instead.
 */
final readonly class FileUploaded extends DomainEvent
{
    public function __construct(
        public Node $node,
        public File $file,
        public FileRevision $version,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): EventType
    {
        return EventType::FileUploaded;
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
            'parent_id' => self::id($this->node->parent_id),
            'revision_id' => self::id($this->version->getKey()),
            'size' => $this->version->size_bytes,
        ];
    }

    public function activityContext(): array
    {
        return [
            'name' => $this->node->name,
            'size_bytes' => $this->version->size_bytes,
            'mime_type' => $this->version->mime_type,
            'version_id' => Identity::stringify($this->version->getKey()),
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->node);
    }
}

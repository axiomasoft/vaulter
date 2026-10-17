<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Files;

use Carbon\CarbonImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Events\DriveEvent;
use Vaulter\Drive\Models\File;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\ValueObjects\Events\ActivityTarget;
use Vaulter\Drive\ValueObjects\Events\JournalEffect;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A new version was committed onto an existing file node: number
 * bumped, current_revision_id repointed, the prior head marked superseded.
 */
#[Api]
final readonly class FileRevisionAdded extends DriveEvent
{
    #[Internal]
    public function __construct(
        public Node $node,
        public File $file,
        public FileRevision $version,
        public int $versionNo,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DriveEventType
    {
        return DriveEventType::FileRevisionAdded;
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
            'revision_id' => self::id($this->version->getKey()),
            'number' => $this->versionNo,
            'size' => $this->version->size_bytes,
        ];
    }

    public function activityContext(): array
    {
        return [
            'number' => $this->versionNo,
            'version_id' => Identity::stringify($this->version->getKey()),
            'size_bytes' => $this->version->size_bytes,
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->node);
    }
}

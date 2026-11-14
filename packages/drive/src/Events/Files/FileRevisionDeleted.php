<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Files;

use Carbon\CarbonImmutable;
use LogicException;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;
use Vaulter\Drive\Data\Snapshots\FileHeadSnapshot;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Events\DriveEvent;
use Vaulter\Drive\Models\File;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\ValueObjects\Events\ActivityTarget;
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
final readonly class FileRevisionDeleted extends DriveEvent
{
    public NodeSnapshot $node;

    private Node $nodeRow;

    public FileHeadSnapshot $file;

    #[Internal]
    public function __construct(
        NodeSnapshotMapper $nodeSnapshots,
        Node $node,
        File $file,
        public int|string $revisionId,
        public int $revisionNo,
        public int $sizeBytes,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        // The public facts are captured here, in the transaction that raised the event; the hooks keep the row.
        $this->nodeRow = $node;
        $this->node = $nodeSnapshots->facts($node);
        $head = $this->node->file;
        $this->file = $head !== null && $head->fileId === SnapshotValue::id($file->getKey())
            ? $head
            : throw new LogicException('[Vaulter] A file event names a node whose file head is not the file of the event.');
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DriveEventType
    {
        return DriveEventType::FileRevisionDeleted;
    }

    public function scope(): EventScope
    {
        return self::nodeScope($this->nodeRow);
    }

    public function subject(): EventSubject
    {
        return self::nodeSubject($this->nodeRow);
    }

    public function sequence(): int
    {
        return $this->nodeRow->sequence;
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
        return ActivityTarget::of($this->nodeRow);
    }
}

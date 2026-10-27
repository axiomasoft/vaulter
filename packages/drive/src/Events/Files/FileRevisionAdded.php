<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Files;

use Carbon\CarbonImmutable;
use LogicException;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;
use Vaulter\Drive\Data\Snapshots\FileHeadSnapshot;
use Vaulter\Drive\Data\Snapshots\FileRevisionSnapshot;
use Vaulter\Drive\Data\Snapshots\Mapping\FileRevisionSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
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
    public NodeSnapshot $node;

    private Node $nodeRow;

    public FileHeadSnapshot $file;

    public FileRevisionSnapshot $version;

    private FileRevision $versionRow;

    #[Internal]
    public function __construct(
        Node $node,
        File $file,
        FileRevision $version,
        public int $versionNo,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        // The public facts are captured here, in the transaction that raised the event; the hooks keep the row.
        $this->nodeRow = $node;
        $this->node = app(NodeSnapshotMapper::class)->facts($node);
        $head = $this->node->file;
        $this->file = $head !== null && $head->fileId === SnapshotValue::id($file->getKey())
            ? $head
            : throw new LogicException('[Vaulter] A file event names a node whose file head is not the file of the event.');
        $this->versionRow = $version;
        $this->version = app(FileRevisionSnapshotMapper::class)->map($version, $file);
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DriveEventType
    {
        return DriveEventType::FileRevisionAdded;
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

    public function journal(): JournalEffect
    {
        return self::nodeUpsert($this->nodeRow, sequence: $this->nodeRow->sequence);
    }

    public function data(): array
    {
        return [
            'revision_id' => self::id($this->versionRow->getKey()),
            'number' => $this->versionNo,
            'size' => $this->versionRow->size_bytes,
        ];
    }

    public function activityContext(): array
    {
        return [
            'number' => $this->versionNo,
            'version_id' => Identity::stringify($this->versionRow->getKey()),
            'size_bytes' => $this->versionRow->size_bytes,
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->nodeRow);
    }
}

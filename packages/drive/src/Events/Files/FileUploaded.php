<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Files;

use Carbon\CarbonImmutable;
use LogicException;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
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
 * A brand-new file node was uploaded — first version committed against a
 * (possibly deduplicated) blob. A subsequent upload onto the same node
 * fires FileRevisionAdded instead.
 */
#[Api]
final readonly class FileUploaded extends DriveEvent
{
    public NodeSnapshot $node;

    private Node $nodeRow;

    public FileHeadSnapshot $file;

    public FileRevisionSnapshot $version;

    private FileRevision $versionRow;

    #[Internal]
    public function __construct(
        NodeSnapshotMapper $nodeSnapshots,
        FileRevisionSnapshotMapper $revisionSnapshots,
        Node $node,
        File $file,
        FileRevision $version,
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
        $this->versionRow = $version;
        $this->version = $revisionSnapshots->map($version, $file);
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DriveEventType
    {
        return DriveEventType::FileUploaded;
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
            'parent_id' => self::id($this->nodeRow->parent_id),
            'revision_id' => self::id($this->versionRow->getKey()),
            'size' => $this->versionRow->size_bytes,
        ];
    }

    public function activityContext(): array
    {
        return [
            'name' => $this->nodeRow->name,
            'size_bytes' => $this->versionRow->size_bytes,
            'mime_type' => $this->versionRow->mime_type,
            'version_id' => Identity::stringify($this->versionRow->getKey()),
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->nodeRow);
    }
}

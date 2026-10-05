<?php

declare(strict_types=1);

namespace Vaulter\Drive\Webhooks;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;
use Vaulter\Drive\Events\Drives\DriveCreated;
use Vaulter\Drive\Events\Files\FileRevisionAdded;
use Vaulter\Drive\Events\Files\FileUploaded;
use Vaulter\Drive\Events\Nodes\FolderCreated;
use Vaulter\Drive\Events\Nodes\NodeCopied;
use Vaulter\Drive\Events\Nodes\NodeMoved;
use Vaulter\Drive\Events\Nodes\NodePurged;
use Vaulter\Drive\Events\Nodes\NodeRenamed;
use Vaulter\Drive\Events\Nodes\NodeRestored;
use Vaulter\Drive\Events\Nodes\NodeTrashed;
use Vaulter\Enums\Events\EventType;
use Vaulter\Support\Identity;
use Vaulter\Webhooks\OutboxRecorder;

/**
 * The vaulter-drive half of the transactional outbox (§2.11 / D14 / M3b, P2 #5): maps
 * the Drive file/folder/upload domain events to the core {@see OutboxRecorder} public
 * seam, staging ONE slim v_outbox row per event (idempotent by event_id). The kernel
 * (Access/Sharing) events are staged by core's own RecordsOutbox; splitting the
 * subscriber by owning package is what lets core stop referencing Vaulter\Drive\Events\*
 * while the outbox payloads retain their domain fields.
 *
 * Registered by VaulterDriveServiceProvider ONLY when the 'reliability' feature is on.
 * The Drive events are ShouldDispatchAfterCommit (after-commit boundary — same
 * crash-window caveat documented on core's RecordsOutbox). RESILIENCE: every handler
 * is wrapped so a staging failure is logged and swallowed, never rolling back the
 * originating action that already committed.
 */
final readonly class RecordsDriveOutbox
{
    public function __construct(
        private OutboxRecorder $recorder,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(DriveCreated::class, [self::class, 'handleStorageCreated']);
        $events->listen(FolderCreated::class, [self::class, 'handleNodeCreated']);
        $events->listen(FileUploaded::class, [self::class, 'handleFileUploaded']);
        $events->listen(FileRevisionAdded::class, [self::class, 'handleVersionCommitted']);
        $events->listen(NodeMoved::class, [self::class, 'handleNodeMoved']);
        $events->listen(NodeRenamed::class, [self::class, 'handleNodeRenamed']);
        $events->listen(NodeCopied::class, [self::class, 'handleNodeCopied']);
        $events->listen(NodeTrashed::class, [self::class, 'handleNodeTrashed']);
        $events->listen(NodeRestored::class, [self::class, 'handleNodeRestored']);
        $events->listen(NodePurged::class, [self::class, 'handleNodeForceDeleted']);
    }

    public function handleStorageCreated(DriveCreated $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->stage(
            event: EventType::DriveCreated,
            eventId: $event->eventId,
            panel: $event->storage->profile,
            topic: $this->recorder->id($event->storage->getKey()),
            payload: [
                'drive_id' => $this->recorder->id($event->storage->getKey()),
                'root_node_id' => $this->recorder->id($event->root->getKey()),
                'name' => $event->storage->name,
            ],
            actorId: $event->actorId,
        ), DriveCreated::class);
    }

    public function handleNodeCreated(FolderCreated $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->stage(
            event: EventType::FolderCreated,
            eventId: $event->eventId,
            panel: $event->node->profile,
            topic: $this->recorder->id($event->node->getKey()),
            payload: [
                'node_id' => $this->recorder->id($event->node->getKey()),
                'drive_id' => $this->recorder->id($event->node->drive_id),
                'parent_id' => $this->recorder->id($event->node->parent_id),
                'kind' => $event->node->kind->value,
                'name' => $event->node->name,
            ],
            actorId: $event->actorId,
        ), FolderCreated::class);
    }

    public function handleFileUploaded(FileUploaded $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->stage(
            event: EventType::FileUploaded,
            eventId: $event->eventId,
            panel: $event->node->profile,
            topic: $this->recorder->id($event->node->getKey()),
            payload: [
                'node_id' => $this->recorder->id($event->node->getKey()),
                'drive_id' => $this->recorder->id($event->node->drive_id),
                'version_id' => $this->recorder->id($event->version->getKey()),
                'name' => $event->node->name,
                'size_bytes' => $event->version->size_bytes,
                'mime_type' => $event->version->mime_type,
            ],
            actorId: $event->actorId,
        ), FileUploaded::class);
    }

    public function handleVersionCommitted(FileRevisionAdded $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->stage(
            event: EventType::FileRevisionAdded,
            eventId: $event->eventId,
            panel: $event->node->profile,
            topic: $this->recorder->id($event->node->getKey()),
            payload: [
                'node_id' => $this->recorder->id($event->node->getKey()),
                'drive_id' => $this->recorder->id($event->node->drive_id),
                'version_id' => $this->recorder->id($event->version->getKey()),
                'number' => $event->versionNo,
                'size_bytes' => $event->version->size_bytes,
            ],
            actorId: $event->actorId,
        ), FileRevisionAdded::class);
    }

    public function handleNodeMoved(NodeMoved $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->stage(
            event: EventType::NodeMoved,
            eventId: $event->eventId,
            panel: $event->node->profile,
            topic: $this->recorder->id($event->node->getKey()),
            payload: [
                'node_id' => $this->recorder->id($event->node->getKey()),
                'drive_id' => $this->recorder->id($event->node->drive_id),
                'from_parent_id' => $this->recorder->id($event->fromParentId),
                'to_parent_id' => $this->recorder->id($event->toParentId),
            ],
            actorId: $event->actorId,
        ), NodeMoved::class);
    }

    public function handleNodeRenamed(NodeRenamed $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->stage(
            event: EventType::NodeRenamed,
            eventId: $event->eventId,
            panel: $event->node->profile,
            topic: $this->recorder->id($event->node->getKey()),
            payload: [
                'node_id' => $this->recorder->id($event->node->getKey()),
                'drive_id' => $this->recorder->id($event->node->drive_id),
                'from' => $event->fromName,
                'to' => $event->toName,
            ],
            actorId: $event->actorId,
        ), NodeRenamed::class);
    }

    public function handleNodeCopied(NodeCopied $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->stage(
            event: EventType::NodeCopied,
            eventId: $event->eventId,
            panel: $event->copy->profile,
            topic: $this->recorder->id($event->copy->getKey()),
            payload: [
                'node_id' => $this->recorder->id($event->copy->getKey()),
                'drive_id' => $this->recorder->id($event->copy->drive_id),
                'source_id' => $this->recorder->id($event->source->getKey()),
                'name' => $event->copy->name,
            ],
            actorId: $event->actorId,
        ), NodeCopied::class);
    }

    public function handleNodeTrashed(NodeTrashed $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->stage(
            event: EventType::NodeTrashed,
            eventId: $event->eventId,
            panel: $event->node->profile,
            topic: $this->recorder->id($event->node->getKey()),
            payload: [
                'node_id' => $this->recorder->id($event->node->getKey()),
                'drive_id' => $this->recorder->id($event->node->drive_id),
                'name' => $event->node->name,
            ],
            actorId: $event->actorId,
        ), NodeTrashed::class);
    }

    public function handleNodeRestored(NodeRestored $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->stage(
            event: EventType::NodeRestored,
            eventId: $event->eventId,
            panel: $event->node->profile,
            topic: $this->recorder->id($event->node->getKey()),
            payload: [
                'node_id' => $this->recorder->id($event->node->getKey()),
                'drive_id' => $this->recorder->id($event->node->drive_id),
                'parent_id' => $this->recorder->id($event->node->parent_id),
                'name' => $event->node->name,
            ],
            actorId: $event->actorId,
        ), NodeRestored::class);
    }

    /**
     * The node objects are gone after a force-delete, so the slim payload carries
     * the deleted ids + freed bytes (the relay still fans this out so a host can
     * reconcile its own mirror). The panel is the default panel — the storage is the
     * only surviving scope and the event does not carry it.
     */
    public function handleNodeForceDeleted(NodePurged $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->stage(
            event: EventType::NodePurged,
            eventId: $event->eventId,
            panel: 'default',
            topic: $this->recorder->id($event->storageId),
            payload: [
                'drive_id' => $this->recorder->id($event->storageId),
                'node_ids' => array_map(Identity::stringify(...), $event->nodeIds),
                'freed_bytes' => $event->freedBytes,
            ],
            actorId: $event->actorId,
        ), NodePurged::class);
    }

    /**
     * Run a recorder call, swallowing + logging any failure. Staging must never break
     * the originating action; the event already committed (after-commit boundary), so
     * a missed outbox row is a logged degradation, not a transaction failure.
     *
     * @param callable(): mixed $record
     */
    private function safely(callable $record, string $event): void
    {
        try {
            $record();
        } catch (Throwable $exception) {
            Log::warning('Vaulter outbox staging failed; the originating action is unaffected.', [
                'event' => $event,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}

<?php

declare(strict_types=1);

namespace Vaulter\Drive\Activity;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;
use Vaulter\Activity\ActivityRecorder;
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
use Vaulter\Drive\Events\Uploads\UploadAborted;
use Vaulter\Drive\Events\Uploads\UploadExpired;
use Vaulter\Drive\Events\Uploads\UploadInitiated;
use Vaulter\Enums\Events\EventType;
use Vaulter\Support\Identity;

/**
 * The vaulter-drive half of the append-only activity feed (§2.9 / M2c-activity, P2 #5):
 * maps the Drive file/folder/upload domain events to the core {@see ActivityRecorder}
 * public seam, writing EXACTLY ONE row per event (recipients resolved at READ — no
 * fan-out, §1.5). The kernel (Access/Sharing) events are mapped by core's own
 * RecordsActivity; splitting the subscriber by owning package is what lets core stop
 * referencing Vaulter\Drive\Events\* while the feed contract stays identical.
 *
 * Registered by VaulterDriveServiceProvider ONLY when the 'activity' feature is on.
 * RESILIENCE: every handler is wrapped so a recorder failure is logged and swallowed —
 * the Drive events are ShouldDispatchAfterCommit, so the originating action already
 * committed and a missed row is a logged degradation, never a rollback.
 */
final readonly class RecordsDriveActivity
{
    public function __construct(
        private ActivityRecorder $recorder,
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
        $events->listen(UploadInitiated::class, [self::class, 'handleUploadSessionInitiated']);
        $events->listen(UploadAborted::class, [self::class, 'handleUploadSessionAborted']);
        $events->listen(UploadExpired::class, [self::class, 'handleUploadSessionExpired']);
    }

    public function handleStorageCreated(DriveCreated $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->record(
            verb: EventType::DriveCreated,
            storageId: Identity::key($event->storage->getKey()),
            tenantId: $event->storage->tenant_id,
            actorId: $event->actorId,
            actor: $event->actor ?? null,
            correlationId: $event->correlationId ?? null,
            target: $event->root,
            context: [
                'drive_id' => Identity::stringify($event->storage->getKey()),
                'owner_type' => $event->storage->owner_type,
                'owner_id' => Identity::stringify($event->storage->owner_id),
                'name' => $event->storage->name,
            ],
        ), DriveCreated::class);
    }

    public function handleNodeCreated(FolderCreated $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->recordForNode(
            verb: EventType::FolderCreated,
            node: $event->node,
            actorId: $event->actorId,
            actor: $event->actor ?? null,
            correlationId: $event->correlationId ?? null,
            context: [
                'kind' => $event->node->kind->value,
                'name' => $event->node->name,
                'parent_id' => Identity::stringify($event->node->parent_id),
            ],
        ), FolderCreated::class);
    }

    public function handleFileUploaded(FileUploaded $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->recordForNode(
            verb: EventType::FileUploaded,
            node: $event->node,
            actorId: $event->actorId,
            actor: $event->actor ?? null,
            correlationId: $event->correlationId ?? null,
            context: [
                'name' => $event->node->name,
                'size_bytes' => $event->version->size_bytes,
                'mime_type' => $event->version->mime_type,
                'version_id' => Identity::stringify($event->version->getKey()),
            ],
        ), FileUploaded::class);
    }

    public function handleVersionCommitted(FileRevisionAdded $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->recordForNode(
            verb: EventType::FileRevisionAdded,
            node: $event->node,
            actorId: $event->actorId,
            actor: $event->actor ?? null,
            correlationId: $event->correlationId ?? null,
            context: [
                'number' => $event->versionNo,
                'version_id' => Identity::stringify($event->version->getKey()),
                'size_bytes' => $event->version->size_bytes,
            ],
        ), FileRevisionAdded::class);
    }

    public function handleNodeMoved(NodeMoved $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->recordForNode(
            verb: EventType::NodeMoved,
            node: $event->node,
            actorId: $event->actorId,
            actor: $event->actor ?? null,
            correlationId: $event->correlationId ?? null,
            context: [
                'from_parent_id' => Identity::stringify($event->fromParentId),
                'to_parent_id' => Identity::stringify($event->toParentId),
            ],
        ), NodeMoved::class);
    }

    public function handleNodeRenamed(NodeRenamed $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->recordForNode(
            verb: EventType::NodeRenamed,
            node: $event->node,
            actorId: $event->actorId,
            actor: $event->actor ?? null,
            correlationId: $event->correlationId ?? null,
            context: [
                'from' => $event->fromName,
                'to' => $event->toName,
            ],
        ), NodeRenamed::class);
    }

    public function handleNodeCopied(NodeCopied $event): void
    {
        // The target is the NEW copy; the source is recorded in context.
        $this->safely(fn (): mixed => $this->recorder->recordForNode(
            verb: EventType::NodeCopied,
            node: $event->copy,
            actorId: $event->actorId,
            actor: $event->actor ?? null,
            correlationId: $event->correlationId ?? null,
            context: [
                'source_id' => Identity::stringify($event->source->getKey()),
                'name' => $event->copy->name,
            ],
        ), NodeCopied::class);
    }

    public function handleNodeTrashed(NodeTrashed $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->recordForNode(
            verb: EventType::NodeTrashed,
            node: $event->node,
            actorId: $event->actorId,
            actor: $event->actor ?? null,
            correlationId: $event->correlationId ?? null,
            context: [
                'name' => $event->node->name,
            ],
        ), NodeTrashed::class);
    }

    public function handleNodeRestored(NodeRestored $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->recordForNode(
            verb: EventType::NodeRestored,
            node: $event->node,
            actorId: $event->actorId,
            actor: $event->actor ?? null,
            correlationId: $event->correlationId ?? null,
            context: [
                'name' => $event->node->name,
                'parent_id' => Identity::stringify($event->node->parent_id),
            ],
        ), NodeRestored::class);
    }

    /**
     * The node objects are gone after a force-delete, so there is no target model:
     * the storage scope plus the deleted ids + freed bytes travel in the context (the
     * row is the only surviving trace).
     */
    public function handleNodeForceDeleted(NodePurged $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->record(
            verb: EventType::NodePurged,
            storageId: $event->storageId,
            actorId: $event->actorId,
            actor: $event->actor ?? null,
            correlationId: $event->correlationId ?? null,
            context: [
                'node_ids' => array_map(Identity::stringify(...), $event->nodeIds),
                'freed_bytes' => $event->freedBytes,
            ],
        ), NodePurged::class);
    }

    /**
     * F-P1.4-13: the upload-session lifecycle audit gap — commit already lands
     * FileUploaded/NodeVersionCommitted, but create/abort/expire had no activity
     * trace at all. These three close it, sharing the same context shape (actor/
     * tenant/storage come from the record() scope scalars; upload_id/protocol/
     * target_name identify the session in the row).
     */
    public function handleUploadSessionInitiated(UploadInitiated $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->record(
            verb: EventType::UploadInitiated,
            storageId: $event->session->drive_id,
            tenantId: $event->session->tenant_id,
            actorId: $event->actorId,
            context: [
                'upload_id' => $event->session->upload_id,
                'protocol' => $event->session->protocol->value,
                'target_name' => $event->session->target_name,
            ],
        ), UploadInitiated::class);
    }

    public function handleUploadSessionAborted(UploadAborted $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->record(
            verb: EventType::UploadAborted,
            storageId: $event->session->drive_id,
            tenantId: $event->session->tenant_id,
            actorId: $event->actorId,
            context: [
                'upload_id' => $event->session->upload_id,
                'protocol' => $event->session->protocol->value,
                'received_bytes' => $event->session->received_bytes,
            ],
        ), UploadAborted::class);
    }

    public function handleUploadSessionExpired(UploadExpired $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->record(
            verb: EventType::UploadExpired,
            storageId: $event->session->drive_id,
            tenantId: $event->session->tenant_id,
            actorId: $event->actorId,
            context: [
                'upload_id' => $event->session->upload_id,
                'protocol' => $event->session->protocol->value,
                'received_bytes' => $event->session->received_bytes,
            ],
        ), UploadExpired::class);
    }

    /**
     * Run a recorder call, swallowing + logging any failure. Audit must never break
     * the originating action; the event already committed (after-commit boundary), so
     * a missed row is a logged degradation, not a transaction failure.
     *
     * @param callable(): mixed $record
     */
    private function safely(callable $record, string $event): void
    {
        try {
            $record();
        } catch (Throwable $exception) {
            Log::warning('Vaulter activity recording failed; the originating action is unaffected.', [
                'event' => $event,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}

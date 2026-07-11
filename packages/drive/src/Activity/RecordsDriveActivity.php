<?php

declare(strict_types=1);

namespace Vaulter\Drive\Activity;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;
use Vaulter\Activity\ActivityRecorder;
use Vaulter\Drive\Events\FileUploaded;
use Vaulter\Drive\Events\NodeCopied;
use Vaulter\Drive\Events\NodeCreated;
use Vaulter\Drive\Events\NodeForceDeleted;
use Vaulter\Drive\Events\NodeMoved;
use Vaulter\Drive\Events\NodeRenamed;
use Vaulter\Drive\Events\NodeRestored;
use Vaulter\Drive\Events\NodeTrashed;
use Vaulter\Drive\Events\NodeVersionCommitted;
use Vaulter\Drive\Events\StorageCreated;
use Vaulter\Drive\Events\UploadSessionAborted;
use Vaulter\Drive\Events\UploadSessionExpired;
use Vaulter\Drive\Events\UploadSessionInitiated;
use Vaulter\Enums\Activity\ActivityVerb;
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
        $events->listen(StorageCreated::class, [self::class, 'handleStorageCreated']);
        $events->listen(NodeCreated::class, [self::class, 'handleNodeCreated']);
        $events->listen(FileUploaded::class, [self::class, 'handleFileUploaded']);
        $events->listen(NodeVersionCommitted::class, [self::class, 'handleVersionCommitted']);
        $events->listen(NodeMoved::class, [self::class, 'handleNodeMoved']);
        $events->listen(NodeRenamed::class, [self::class, 'handleNodeRenamed']);
        $events->listen(NodeCopied::class, [self::class, 'handleNodeCopied']);
        $events->listen(NodeTrashed::class, [self::class, 'handleNodeTrashed']);
        $events->listen(NodeRestored::class, [self::class, 'handleNodeRestored']);
        $events->listen(NodeForceDeleted::class, [self::class, 'handleNodeForceDeleted']);
        $events->listen(UploadSessionInitiated::class, [self::class, 'handleUploadSessionInitiated']);
        $events->listen(UploadSessionAborted::class, [self::class, 'handleUploadSessionAborted']);
        $events->listen(UploadSessionExpired::class, [self::class, 'handleUploadSessionExpired']);
    }

    public function handleStorageCreated(StorageCreated $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->record(
            verb: ActivityVerb::StorageCreated,
            storageId: Identity::key($event->storage->getKey()),
            tenantId: $event->storage->tenant_id,
            actorId: $event->actorId,
            target: $event->root,
            context: [
                'storage_id' => Identity::stringify($event->storage->getKey()),
                'owner_type' => $event->storage->owner_type,
                'owner_id' => Identity::stringify($event->storage->owner_id),
                'name' => $event->storage->name,
            ],
        ), StorageCreated::class);
    }

    public function handleNodeCreated(NodeCreated $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->recordForNode(
            verb: ActivityVerb::NodeCreated,
            node: $event->node,
            actorId: $event->actorId,
            context: [
                'kind' => $event->node->kind->value,
                'name' => $event->node->name,
                'parent_id' => Identity::stringify($event->node->parent_id),
            ],
        ), NodeCreated::class);
    }

    public function handleFileUploaded(FileUploaded $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->recordForNode(
            verb: ActivityVerb::FileUploaded,
            node: $event->node,
            actorId: $event->actorId,
            context: [
                'name' => $event->node->name,
                'size_bytes' => $event->version->size_bytes,
                'mime_type' => $event->version->mime_type,
                'version_id' => Identity::stringify($event->version->getKey()),
            ],
        ), FileUploaded::class);
    }

    public function handleVersionCommitted(NodeVersionCommitted $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->recordForNode(
            verb: ActivityVerb::VersionCreated,
            node: $event->node,
            actorId: $event->actorId,
            context: [
                'version_no' => $event->versionNo,
                'version_id' => Identity::stringify($event->version->getKey()),
                'size_bytes' => $event->version->size_bytes,
            ],
        ), NodeVersionCommitted::class);
    }

    public function handleNodeMoved(NodeMoved $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->recordForNode(
            verb: ActivityVerb::NodeMoved,
            node: $event->node,
            actorId: $event->actorId,
            context: [
                'from_parent_id' => Identity::stringify($event->fromParentId),
                'to_parent_id' => Identity::stringify($event->toParentId),
            ],
        ), NodeMoved::class);
    }

    public function handleNodeRenamed(NodeRenamed $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->recordForNode(
            verb: ActivityVerb::NodeRenamed,
            node: $event->node,
            actorId: $event->actorId,
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
            verb: ActivityVerb::NodeCopied,
            node: $event->copy,
            actorId: $event->actorId,
            context: [
                'source_id' => Identity::stringify($event->source->getKey()),
                'name' => $event->copy->name,
            ],
        ), NodeCopied::class);
    }

    public function handleNodeTrashed(NodeTrashed $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->recordForNode(
            verb: ActivityVerb::NodeTrashed,
            node: $event->node,
            actorId: $event->actorId,
            context: [
                'name' => $event->node->name,
            ],
        ), NodeTrashed::class);
    }

    public function handleNodeRestored(NodeRestored $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->recordForNode(
            verb: ActivityVerb::NodeRestored,
            node: $event->node,
            actorId: $event->actorId,
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
    public function handleNodeForceDeleted(NodeForceDeleted $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->record(
            verb: ActivityVerb::NodeForceDeleted,
            storageId: $event->storageId,
            actorId: $event->actorId,
            context: [
                'node_ids' => array_map(Identity::stringify(...), $event->nodeIds),
                'freed_bytes' => $event->freedBytes,
            ],
        ), NodeForceDeleted::class);
    }

    /**
     * F-P1.4-13: the upload-session lifecycle audit gap — commit already lands
     * FileUploaded/NodeVersionCommitted, but create/abort/expire had no activity
     * trace at all. These three close it, sharing the same context shape (actor/
     * tenant/storage come from the record() scope scalars; upload_id/protocol/
     * target_name identify the session in the row).
     */
    public function handleUploadSessionInitiated(UploadSessionInitiated $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->record(
            verb: ActivityVerb::UploadSessionInitiated,
            storageId: $event->session->storage_id,
            tenantId: $event->session->tenant_id,
            actorId: $event->actorId,
            context: [
                'upload_id' => $event->session->upload_id,
                'protocol' => $event->session->protocol->value,
                'target_name' => $event->session->target_name,
            ],
        ), UploadSessionInitiated::class);
    }

    public function handleUploadSessionAborted(UploadSessionAborted $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->record(
            verb: ActivityVerb::UploadSessionAborted,
            storageId: $event->session->storage_id,
            tenantId: $event->session->tenant_id,
            actorId: $event->actorId,
            context: [
                'upload_id' => $event->session->upload_id,
                'protocol' => $event->session->protocol->value,
                'received_bytes' => $event->session->received_bytes,
            ],
        ), UploadSessionAborted::class);
    }

    public function handleUploadSessionExpired(UploadSessionExpired $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->record(
            verb: ActivityVerb::UploadSessionExpired,
            storageId: $event->session->storage_id,
            tenantId: $event->session->tenant_id,
            actorId: $event->actorId,
            context: [
                'upload_id' => $event->session->upload_id,
                'protocol' => $event->session->protocol->value,
                'received_bytes' => $event->session->received_bytes,
            ],
        ), UploadSessionExpired::class);
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

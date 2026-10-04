<?php

declare(strict_types=1);

namespace Vaulter\Drive\Webhooks;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;
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
use Vaulter\Enums\Webhooks\WebhookEvent;
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
    }

    public function handleStorageCreated(StorageCreated $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->stage(
            event: WebhookEvent::StorageCreated,
            eventId: $event->eventId,
            panel: $event->storage->panel,
            topic: $this->recorder->id($event->storage->getKey()),
            payload: [
                'storage_id' => $this->recorder->id($event->storage->getKey()),
                'root_node_id' => $this->recorder->id($event->root->getKey()),
                'name' => $event->storage->name,
            ],
            actorId: $event->actorId,
        ), StorageCreated::class);
    }

    public function handleNodeCreated(NodeCreated $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->stage(
            event: WebhookEvent::NodeCreated,
            eventId: $event->eventId,
            panel: $event->node->panel,
            topic: $this->recorder->id($event->node->getKey()),
            payload: [
                'node_id' => $this->recorder->id($event->node->getKey()),
                'storage_id' => $this->recorder->id($event->node->storage_id),
                'parent_id' => $this->recorder->id($event->node->parent_id),
                'kind' => $event->node->kind->value,
                'name' => $event->node->name,
            ],
            actorId: $event->actorId,
        ), NodeCreated::class);
    }

    public function handleFileUploaded(FileUploaded $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->stage(
            event: WebhookEvent::FileUploaded,
            eventId: $event->eventId,
            panel: $event->node->panel,
            topic: $this->recorder->id($event->node->getKey()),
            payload: [
                'node_id' => $this->recorder->id($event->node->getKey()),
                'storage_id' => $this->recorder->id($event->node->storage_id),
                'version_id' => $this->recorder->id($event->version->getKey()),
                'name' => $event->node->name,
                'size_bytes' => $event->version->size_bytes,
                'mime_type' => $event->version->mime_type,
            ],
            actorId: $event->actorId,
        ), FileUploaded::class);
    }

    public function handleVersionCommitted(NodeVersionCommitted $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->stage(
            event: WebhookEvent::VersionCreated,
            eventId: $event->eventId,
            panel: $event->node->panel,
            topic: $this->recorder->id($event->node->getKey()),
            payload: [
                'node_id' => $this->recorder->id($event->node->getKey()),
                'storage_id' => $this->recorder->id($event->node->storage_id),
                'version_id' => $this->recorder->id($event->version->getKey()),
                'version_no' => $event->versionNo,
                'size_bytes' => $event->version->size_bytes,
            ],
            actorId: $event->actorId,
        ), NodeVersionCommitted::class);
    }

    public function handleNodeMoved(NodeMoved $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->stage(
            event: WebhookEvent::NodeMoved,
            eventId: $event->eventId,
            panel: $event->node->panel,
            topic: $this->recorder->id($event->node->getKey()),
            payload: [
                'node_id' => $this->recorder->id($event->node->getKey()),
                'storage_id' => $this->recorder->id($event->node->storage_id),
                'from_parent_id' => $this->recorder->id($event->fromParentId),
                'to_parent_id' => $this->recorder->id($event->toParentId),
            ],
            actorId: $event->actorId,
        ), NodeMoved::class);
    }

    public function handleNodeRenamed(NodeRenamed $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->stage(
            event: WebhookEvent::NodeRenamed,
            eventId: $event->eventId,
            panel: $event->node->panel,
            topic: $this->recorder->id($event->node->getKey()),
            payload: [
                'node_id' => $this->recorder->id($event->node->getKey()),
                'storage_id' => $this->recorder->id($event->node->storage_id),
                'from' => $event->fromName,
                'to' => $event->toName,
            ],
            actorId: $event->actorId,
        ), NodeRenamed::class);
    }

    public function handleNodeCopied(NodeCopied $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->stage(
            event: WebhookEvent::NodeCopied,
            eventId: $event->eventId,
            panel: $event->copy->panel,
            topic: $this->recorder->id($event->copy->getKey()),
            payload: [
                'node_id' => $this->recorder->id($event->copy->getKey()),
                'storage_id' => $this->recorder->id($event->copy->storage_id),
                'source_id' => $this->recorder->id($event->source->getKey()),
                'name' => $event->copy->name,
            ],
            actorId: $event->actorId,
        ), NodeCopied::class);
    }

    public function handleNodeTrashed(NodeTrashed $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->stage(
            event: WebhookEvent::NodeTrashed,
            eventId: $event->eventId,
            panel: $event->node->panel,
            topic: $this->recorder->id($event->node->getKey()),
            payload: [
                'node_id' => $this->recorder->id($event->node->getKey()),
                'storage_id' => $this->recorder->id($event->node->storage_id),
                'name' => $event->node->name,
            ],
            actorId: $event->actorId,
        ), NodeTrashed::class);
    }

    public function handleNodeRestored(NodeRestored $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->stage(
            event: WebhookEvent::NodeRestored,
            eventId: $event->eventId,
            panel: $event->node->panel,
            topic: $this->recorder->id($event->node->getKey()),
            payload: [
                'node_id' => $this->recorder->id($event->node->getKey()),
                'storage_id' => $this->recorder->id($event->node->storage_id),
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
    public function handleNodeForceDeleted(NodeForceDeleted $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->stage(
            event: WebhookEvent::NodeForceDeleted,
            eventId: $event->eventId,
            panel: 'default',
            topic: $this->recorder->id($event->storageId),
            payload: [
                'storage_id' => $this->recorder->id($event->storageId),
                'node_ids' => array_map(Identity::stringify(...), $event->nodeIds),
                'freed_bytes' => $event->freedBytes,
            ],
            actorId: $event->actorId,
        ), NodeForceDeleted::class);
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

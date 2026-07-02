<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

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
use Vaulter\Events\Access\NodeGrantCreated;
use Vaulter\Events\Access\NodeGrantRevoked;
use Vaulter\Events\Sharing\NodeShareCreated;
use Vaulter\Events\Sharing\NodeShareRevoked;

/**
 * The Laravel event subscriber that stages domain events in the transactional
 * outbox (master plan §2.11 / D14 / M3b). Registered in the service provider ONLY
 * when the 'reliability' feature is enabled, it routes each Drive domain event to the
 * OutboxRecorder, which writes ONE v_outbox row (idempotent by event_id).
 *
 * TRANSACTIONAL-OUTBOX SEMANTICS + CAVEAT: the Drive events are
 * ShouldDispatchAfterCommit, so this subscriber runs on the AFTER-COMMIT boundary —
 * the same non-invasive seam the activity feed uses. The outbox row is therefore
 * written in the SAME REQUEST as the mutation but a hair AFTER the mutation's commit,
 * NOT inside its transaction. The trade-off is a small crash window: if the process
 * dies between the mutation's commit and this subscriber writing the row, the event is
 * lost to the outbox (the mutation still stands). This is the deliberate, documented
 * cost of not refactoring every Action to write the outbox row inside its own
 * transaction; the relay/delivery is at-least-once and consumers DEDUPE on event_id,
 * so the only failure mode is at-most-once for that one crashed request, never a
 * double-delivery.
 *
 * RESILIENCE: every handler is wrapped so a recorder exception is logged and
 * swallowed — staging an event must never break the originating action, which already
 * committed.
 */
final readonly class RecordsOutbox
{
    public function __construct(
        private OutboxRecorder $recorder,
    ) {}

    /**
     * Map every fanned-out event to its handler. The dispatcher invokes the named
     * method with the event instance.
     */
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
        $events->listen(NodeGrantCreated::class, [self::class, 'handleGrantCreated']);
        $events->listen(NodeGrantRevoked::class, [self::class, 'handleGrantRevoked']);
        $events->listen(NodeShareCreated::class, [self::class, 'handleShareCreated']);
        $events->listen(NodeShareRevoked::class, [self::class, 'handleShareRevoked']);
    }

    public function handleStorageCreated(StorageCreated $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->recordStorageCreated($event), StorageCreated::class);
    }

    public function handleNodeCreated(NodeCreated $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->recordNodeCreated($event), NodeCreated::class);
    }

    public function handleFileUploaded(FileUploaded $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->recordFileUploaded($event), FileUploaded::class);
    }

    public function handleVersionCommitted(NodeVersionCommitted $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->recordVersionCommitted($event), NodeVersionCommitted::class);
    }

    public function handleNodeMoved(NodeMoved $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->recordNodeMoved($event), NodeMoved::class);
    }

    public function handleNodeRenamed(NodeRenamed $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->recordNodeRenamed($event), NodeRenamed::class);
    }

    public function handleNodeCopied(NodeCopied $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->recordNodeCopied($event), NodeCopied::class);
    }

    public function handleNodeTrashed(NodeTrashed $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->recordNodeTrashed($event), NodeTrashed::class);
    }

    public function handleNodeRestored(NodeRestored $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->recordNodeRestored($event), NodeRestored::class);
    }

    public function handleNodeForceDeleted(NodeForceDeleted $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->recordNodeForceDeleted($event), NodeForceDeleted::class);
    }

    public function handleGrantCreated(NodeGrantCreated $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->recordGrantCreated($event), NodeGrantCreated::class);
    }

    public function handleGrantRevoked(NodeGrantRevoked $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->recordGrantRevoked($event), NodeGrantRevoked::class);
    }

    public function handleShareCreated(NodeShareCreated $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->recordShareCreated($event), NodeShareCreated::class);
    }

    public function handleShareRevoked(NodeShareRevoked $event): void
    {
        $this->safely(fn (): mixed => $this->recorder->recordShareRevoked($event), NodeShareRevoked::class);
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

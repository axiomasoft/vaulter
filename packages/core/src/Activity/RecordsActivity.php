<?php

declare(strict_types=1);

namespace Vaulter\Activity;

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
 * The Laravel event subscriber that drives the append-only activity feed (§2.9 /
 * M2c-activity). Registered in the service provider ONLY when the 'activity'
 * feature is enabled, it routes each Drive domain event to the ActivityRecorder,
 * which writes EXACTLY ONE row (recipients resolved at READ — no fan-out, §1.5).
 *
 * RESILIENCE (the originating action must never break for an audit failure): every
 * handler is wrapped so a recorder exception is logged and swallowed. The events
 * are ShouldDispatchAfterCommit, so the handler runs on the after-commit boundary —
 * a failed recording can never roll back the action that already succeeded.
 */
final readonly class RecordsActivity
{
    public function __construct(
        private ActivityRecorder $recorder,
    ) {}

    /**
     * Map every audited event to its handler. The dispatcher invokes the named
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
     * Run a recorder call, swallowing + logging any failure. Audit must never break
     * the originating action; the event already committed (after-commit boundary),
     * so a missed row is a logged degradation, not a transaction failure.
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

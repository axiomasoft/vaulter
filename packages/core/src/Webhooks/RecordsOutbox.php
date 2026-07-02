<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;
use Vaulter\Events\Access\NodeGrantCreated;
use Vaulter\Events\Access\NodeGrantRevoked;
use Vaulter\Events\Sharing\NodeShareCreated;
use Vaulter\Events\Sharing\NodeShareRevoked;

/**
 * The Laravel event subscriber that stages the KERNEL domain events (Access grants,
 * Sharing links) in the transactional outbox (master plan §2.11 / D14 / M3b).
 * Registered in the core service provider ONLY when the 'reliability' feature is
 * enabled, it routes each kernel event to the OutboxRecorder, which writes ONE
 * v_outbox row (idempotent by event_id).
 *
 * The Drive file/folder/upload events are staged by the twin subscriber in
 * vaulter-drive (P2 #5 / ADR-0015) through the same OutboxRecorder public seam — core
 * no longer references Vaulter\Drive\Events\*, and the outbox contract is unchanged.
 *
 * TRANSACTIONAL-OUTBOX SEMANTICS + CAVEAT: the domain events are
 * ShouldDispatchAfterCommit, so this subscriber runs on the AFTER-COMMIT boundary —
 * the outbox row is written in the SAME REQUEST as the mutation but a hair AFTER its
 * commit, NOT inside its transaction. The trade-off is a small crash window: if the
 * process dies between the mutation's commit and this subscriber writing the row, the
 * event is lost to the outbox (the mutation still stands). Delivery is at-least-once
 * and consumers DEDUPE on event_id, so the only failure mode is at-most-once for that
 * one crashed request, never a double-delivery.
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
     * Map every fanned-out kernel event to its handler. The dispatcher invokes the
     * named method with the event instance.
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(NodeGrantCreated::class, [self::class, 'handleGrantCreated']);
        $events->listen(NodeGrantRevoked::class, [self::class, 'handleGrantRevoked']);
        $events->listen(NodeShareCreated::class, [self::class, 'handleShareCreated']);
        $events->listen(NodeShareRevoked::class, [self::class, 'handleShareRevoked']);
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

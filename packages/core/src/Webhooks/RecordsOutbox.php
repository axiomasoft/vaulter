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
 * TRANSACTIONAL-OUTBOX SEMANTICS: the kernel domain events are dispatched INSIDE the
 * mutation transaction (F-P1.7-03), so this subscriber writes the outbox row in the
 * SAME transaction as the mutation — the two commit (or roll back) together. There is
 * no crash window between the mutation's commit and staging, giving true at-least-once;
 * consumers still DEDUPE on event_id.
 *
 * RESILIENCE: every handler is wrapped so a recorder exception is logged and
 * swallowed — staging an event must never break the originating action. Because the
 * failure is swallowed, a staging error never rolls the mutation back even though it
 * now runs inside the transaction.
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

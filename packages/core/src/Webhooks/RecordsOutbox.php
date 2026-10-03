<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;
use Vaulter\Database\VaulterDatabase;
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
 * handler now runs INSIDE the mutation transaction, the recorder call is wrapped in a
 * SAVEPOINT: on PostgreSQL a failed statement aborts the whole transaction, so merely
 * catching the exception would leave it poisoned and the mutation's COMMIT would fail
 * (silently rolling the grant/share back). The savepoint confines a staging failure to
 * itself, so it truly never rolls the mutation back (review P0.2 / F-P1.7-03).
 */
final readonly class RecordsOutbox
{
    public function __construct(
        private OutboxRecorder $recorder,
        private VaulterDatabase $database,
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
     * the originating action. The handler fires INSIDE the mutation transaction
     * (F-P1.7-03), so the call is wrapped in a SAVEPOINT (a nested VaulterDatabase::transaction): a
     * failed staging statement rolls back to the savepoint — un-poisoning the outer
     * transaction on PostgreSQL — instead of aborting the mutation's COMMIT. A missed
     * outbox row is then a logged degradation, not a mutation rollback.
     *
     * @param callable(): mixed $record
     */
    private function safely(callable $record, string $event): void
    {
        try {
            $this->database->transaction(fn (): mixed => $record());
        } catch (Throwable $exception) {
            Log::warning('Vaulter outbox staging failed; the originating action is unaffected.', [
                'event' => $event,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}

<?php

declare(strict_types=1);

namespace Vaulter\Activity;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Vaulter\Events\Access\NodeGrantCreated;
use Vaulter\Events\Access\NodeGrantRevoked;
use Vaulter\Events\Sharing\NodeShareCreated;
use Vaulter\Events\Sharing\NodeShareRevoked;

/**
 * The Laravel event subscriber that drives the append-only activity feed (§2.9 /
 * M2c-activity) for the KERNEL domains (Access grants, Sharing links). Registered in
 * the core service provider ONLY when the 'activity' feature is enabled, it routes
 * each kernel event to the ActivityRecorder, which writes EXACTLY ONE row (recipients
 * resolved at READ — no fan-out, §1.5).
 *
 * The Drive file/folder/upload events are mapped by the twin subscriber in
 * vaulter-drive (P2 #5 / ADR-0015) through the same ActivityRecorder public seam, so
 * core no longer references Vaulter\Drive\Events\* — the feed's one-row contract is
 * unchanged, just split by owning package.
 *
 * RESILIENCE (the originating action must never break for an audit failure): every
 * handler is wrapped so a recorder exception is logged and swallowed. The kernel events
 * now fire INSIDE the mutation transaction (F-P1.7-03), so the recorder call is wrapped
 * in a SAVEPOINT: on PostgreSQL a failed statement poisons the whole transaction, and
 * merely swallowing the exception would fail the mutation's COMMIT. The savepoint
 * confines a recording failure so it still can never roll back the action.
 */
final readonly class RecordsActivity
{
    public function __construct(
        private ActivityRecorder $recorder,
    ) {}

    /**
     * Map every audited kernel event to its handler. The dispatcher invokes the named
     * method with the event instance.
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
     * Run a recorder call, swallowing + logging any failure. Audit must never break
     * the originating action. The handler fires INSIDE the mutation transaction
     * (F-P1.7-03), so the call is wrapped in a SAVEPOINT (a nested DB::transaction): a
     * failed recording rolls back to the savepoint — un-poisoning the outer transaction
     * on PostgreSQL — instead of aborting the mutation's COMMIT. A missed row is then a
     * logged degradation, not a mutation rollback.
     *
     * @param callable(): mixed $record
     */
    private function safely(callable $record, string $event): void
    {
        try {
            DB::transaction(fn (): mixed => $record());
        } catch (Throwable $exception) {
            Log::warning('Vaulter activity recording failed; the originating action is unaffected.', [
                'event' => $event,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
